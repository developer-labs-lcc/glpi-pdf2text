<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.1
 * @created   2026-10-22
 * @changed   2026-11-15
 */

namespace GlpiPlugin\Pdf2text;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

use function Safe\fclose;
use function Safe\filesize;
use function Safe\preg_replace;
use function Safe\proc_close;
use function Safe\stream_get_contents;
use function Safe\stream_set_blocking;

/**
 * Reads the text layer of a PDF file.
 *
 * Uses the pdftotext program (poppler-utils) when it is available, because it keeps the layout
 * of tables and columns. Otherwise the bundled pure PHP parser (smalot/pdfparser) is used.
 * Scanned PDFs (images only) have no text layer; the result is then an empty string (no OCR).
 *
 * The class does not depend on GLPI, so it can be tested on its own.
 */
final class PdfTextExtractor
{
    /** Places where pdftotext is usually installed (Debian/Ubuntu, RHEL, Alpine, macOS/Homebrew). */
    private const PDFTOTEXT_CANDIDATES = [
        '/usr/bin/pdftotext',
        '/usr/local/bin/pdftotext',
        '/opt/homebrew/bin/pdftotext',
    ];

    /** Maximum run time of pdftotext in seconds. */
    private const TIMEOUT = 30;

    /** Larger files are not given to the PHP parser, it keeps the whole file in memory. */
    private const PHP_PARSER_MAX_BYTES = 20 * 1024 * 1024;

    /**
     * @param string $pdftotext_path configured path of pdftotext; empty = auto-detect
     */
    public function __construct(private readonly string $pdftotext_path = '') {}

    /**
     * Returns the normalised text of the PDF, or an empty string if it has no text layer.
     *
     * @throws RuntimeException if the file cannot be read by any method
     */
    public function extract(string $file): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(sprintf('File "%s" is not readable.', $file));
        }

        $binary = $this->findPdftotext();
        if ($binary !== null) {
            return self::normalise($this->runPdftotext($binary, $file));
        }

        return self::normalise($this->runPhpParser($file));
    }

    /**
     * Name of the method extract() will use, for display on the configuration page.
     */
    public function getMethod(): string
    {
        $binary = $this->findPdftotext();

        return $binary !== null ? 'pdftotext (' . $binary . ')' : 'smalot/pdfparser (PHP)';
    }

    private function findPdftotext(): ?string
    {
        $candidates = $this->pdftotext_path !== '' ? [$this->pdftotext_path] : self::PDFTOTEXT_CANDIDATES;
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function runPdftotext(string $binary, string $file): string
    {
        // Array form of proc_open: no shell is involved, so file names need no escaping.
        // -layout keeps columns and tables readable, "-" writes the text to stdout.
        // PHP's own proc_open() is used on purpose: the type declaration of Safe\proc_open()
        // only accepts a command string, which would need a shell.
        /** @phpstan-ignore theCodingMachineSafe.function (array command, result checked below) */
        $process = \proc_open(
            [$binary, '-layout', '-enc', 'UTF-8', '-q', $file, '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($process) || !is_resource($stdout) || !is_resource($stderr)) {
            throw new RuntimeException('pdftotext could not be started.');
        }

        // Read without blocking so that a hanging pdftotext can be stopped after TIMEOUT seconds.
        stream_set_blocking($stdout, false);
        stream_set_blocking($stderr, false);
        $output   = '';
        $errors   = '';
        $deadline = microtime(true) + self::TIMEOUT;
        while (true) {
            $output .= stream_get_contents($stdout);
            $errors .= stream_get_contents($stderr);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($stdout);
                fclose($stderr);
                proc_close($process);
                throw new RuntimeException(sprintf('pdftotext did not finish within %d seconds.', self::TIMEOUT));
            }
            usleep(20000);
        }
        // Collect what was written between the last read and the end of the process.
        $output .= stream_get_contents($stdout);
        $errors .= stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        proc_close($process);

        // proc_get_status() reports the exit code only once, in the call that sees the end.
        if ($status['exitcode'] !== 0) {
            throw new RuntimeException(sprintf('pdftotext failed (exit code %d): %s', $status['exitcode'], trim($errors)));
        }

        return $output;
    }

    private function runPhpParser(string $file): string
    {
        if (filesize($file) > self::PHP_PARSER_MAX_BYTES) {
            throw new RuntimeException('The PDF is too large for the PHP parser; install pdftotext (poppler-utils).');
        }

        try {
            return (new Parser())->parseFile($file)->getText();
        } catch (Throwable $e) {
            throw new RuntimeException('The PDF could not be parsed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Cleans up the raw text: valid UTF-8, unix line breaks, no page breaks,
     * no trailing spaces and at most one empty line in a row.
     */
    public static function normalise(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\f"], ["\n", "\n", "\n"], $text);
        // Remove spaces and tabs at the end of every line (m = ^/$ match at each line).
        $text = preg_replace('/[ \t]+$/m', '', $text);
        // Three or more line breaks in a row (= two or more empty lines) become one empty line.
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text, "\n");
    }

    /**
     * Shortens the text to $max characters (not bytes, so no UTF-8 character is cut in half).
     *
     * @return array{0: string, 1: bool} the text and whether it was shortened
     */
    public static function truncate(string $text, int $max): array
    {
        if (mb_strlen($text) <= $max) {
            return [$text, false];
        }

        return [rtrim(mb_substr($text, 0, $max)), true];
    }
}
