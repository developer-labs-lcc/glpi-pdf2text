<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.3.0
 * @created   2026-10-22
 * @changed   2026-11-19
 */

namespace GlpiPlugin\Pdf2text;

use RuntimeException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

use function Safe\fclose;
use function Safe\file_get_contents;
use function Safe\filesize;
use function Safe\inflate_add;
use function Safe\inflate_init;
use function Safe\ini_get;
use function Safe\preg_match;
use function Safe\preg_replace;
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

    /** Unpacked size of the compressed streams of a PDF up to which the PHP parser is used. */
    private const PHP_PARSER_MAX_UNPACKED = 32 * 1024 * 1024;

    /** Most bytes the PHP parser unpacks from one stream (its own limit, for every filter). */
    private const PHP_PARSER_STREAM_LIMIT = 16 * 1024 * 1024;

    /**
     * Most a byte of LZW or RunLength data can grow to when unpacked (LZW: one 9-bit code stands
     * for up to 4096 bytes; RunLength: 2 bytes for up to 128), used to count such streams in
     * unpackedSize() without unpacking them.
     */
    private const LZW_FACTOR       = 3641;
    private const RUNLENGTH_FACTOR = 64;

    /** Memory the PHP parser needs, as a multiple of the size of the PDF (rough, on the safe side). */
    private const PHP_PARSER_MEMORY_FACTOR = 15;

    /**
     * @param string $pdftotext_path configured path of pdftotext; empty = auto-detect
     */
    public function __construct(private readonly string $pdftotext_path = '') {}

    /** Budget in bytes for the output of pdftotext when no maximum length is given. */
    private const DEFAULT_BUDGET = 4 * 1024 * 1024;

    /** At most this much of the error output of pdftotext is kept (for the log). */
    private const MAX_ERROR_BYTES = 65536;

    /** Whether the last text was cut because it got longer than the maximum length. */
    private bool $cut = false;

    /**
     * Whether a path names the pdftotext program: its file name must be "pdftotext" (or
     * "pdftotext.exe"), so that a mistyped or unexpected path does not run another program for
     * every attachment.
     */
    public static function isPdftotextPath(string $path): bool
    {
        // ^pdftotext(\.exe)?$ = exactly "pdftotext", optionally with ".exe"; i = any case
        return preg_match('/^pdftotext(\.exe)?$/i', basename($path)) === 1;
    }

    /**
     * Whether the last text returned by extract() was cut at the maximum length (pdftotext was
     * stopped then, its output is not read to the end).
     */
    public function wasCut(): bool
    {
        return $this->cut;
    }

    /**
     * Returns the normalised text of the PDF, or an empty string if it has no text layer.
     * The output of pdftotext is read only up to $max_chars characters (plus a margin; 0 = about
     * 4 MB): a small, highly compressed PDF can produce a huge text.
     *
     * @throws RuntimeException if the file cannot be read by any method
     */
    public function extract(string $file, int $max_chars = 0): string
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException(sprintf('File "%s" is not readable.', $file));
        }
        $this->cut = false;

        $binary = $this->findPdftotext();
        if ($binary !== null) {
            // UTF-8: up to 4 bytes per character; 1 KB margin, so that the shortening later sees there was more
            $budget = $max_chars > 0 ? $max_chars * 4 + 1024 : self::DEFAULT_BUDGET;
            return self::normalise($this->runPdftotext($binary, $file, $budget));
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
            // Absolute path only: a relative one would be searched in PATH by proc_open()
            if (str_starts_with($candidate, '/') && self::isPdftotextPath($candidate) && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function runPdftotext(string $binary, string $file, int $budget): string
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
            // At most the budget (+1 byte, to notice that there is more)
            $output .= stream_get_contents($stdout, $budget + 1 - strlen($output));
            $errors .= stream_get_contents($stderr, max(0, self::MAX_ERROR_BYTES - strlen($errors)));
            if (strlen($output) > $budget) {
                // Enough text: pdftotext is stopped, the rest is not needed
                proc_terminate($process);
                fclose($stdout);
                fclose($stderr);
                self::closeProcess($process);
                $this->cut = true;
                // mb_strcut: at most $budget bytes, without splitting a character
                return mb_strcut($output, 0, $budget, 'UTF-8');
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                fclose($stdout);
                fclose($stderr);
                self::closeProcess($process);
                throw new RuntimeException(sprintf('pdftotext did not finish within %d seconds.', self::TIMEOUT));
            }
            usleep(20000);
        }
        // Collect what was written between the last read and the end of the process.
        $output .= stream_get_contents($stdout, $budget + 1 - strlen($output));
        $errors .= stream_get_contents($stderr, max(0, self::MAX_ERROR_BYTES - strlen($errors)));
        if (strlen($output) > $budget) {
            $this->cut = true;
            $output    = mb_strcut($output, 0, $budget, 'UTF-8');
        }
        fclose($stdout);
        fclose($stderr);
        self::closeProcess($process);

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

        // The parser keeps the file and its objects in memory, many times the size of the file,
        // and unpacks all compressed streams: their unpacked size counts as well (a small PDF can
        // unpack to hundreds of megabytes)
        $unpacked = self::unpackedSize($file, self::PHP_PARSER_MAX_UNPACKED);
        if ($unpacked > self::PHP_PARSER_MAX_UNPACKED) {
            throw new RuntimeException(sprintf('The PDF unpacks to more than %d MB, too much for the PHP parser; install pdftotext (poppler-utils).', self::PHP_PARSER_MAX_UNPACKED / 1048576));
        }
        self::ensureMemory((filesize($file) + $unpacked) * self::PHP_PARSER_MEMORY_FACTOR);
        try {
            // Each compressed stream is unpacked to at most PHP_PARSER_STREAM_LIMIT bytes (FlateDecode,
            // LZWDecode, RunLengthDecode), images are not kept in memory
            $config = new Config();
            $config->setDecodeMemoryLimit(self::PHP_PARSER_STREAM_LIMIT);
            $config->setRetainImageContent(false);

            return (new Parser([], $config))->parseFile($file)->getText();
        } catch (Throwable $e) {
            throw new RuntimeException('The PDF could not be parsed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Closes the process of pdftotext. PHP's own proc_close() on purpose: before PHP 8.3 it
     * returns -1 once proc_get_status() has seen the end of the process (as in runPdftotext()),
     * which the variant of the Safe library turns into an exception; the exit code is taken
     * from proc_get_status() instead.
     *
     * @param resource $process
     */
    private static function closeProcess($process): void
    {
        /** @phpstan-ignore theCodingMachineSafe.function (result not needed, see above) */
        \proc_close($process);
    }

    /**
     * Throws if fewer than $bytes of memory are left below PHP's memory_limit. Running out of
     * memory ends PHP at once (no exception, nothing can catch it): in the mail collector, the
     * mail would not be finished and could be imported again. So a file that would need too much
     * is skipped and logged instead.
     *
     * @throws RuntimeException if the memory is not enough
     */
    public static function ensureMemory(int $bytes): void
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $bytes > $limit) {
            throw new RuntimeException(sprintf('Not enough memory to read the file (about %d MB needed, memory_limit %s).', (int) ceil($bytes / 1048576), (string) ini_get('memory_limit')));
        }
    }

    /**
     * Size of the compressed streams of a PDF once unpacked, counted without keeping the data:
     * zlib streams (FlateDecode, by far the most common filter) are unpacked in small pieces and
     * only the length of each piece is added up; LZW and RunLength streams count with the most
     * they could grow to. Counting stops above $limit. Other streams (images, ASCII filters, which
     * only shrink) count with their packed size.
     */
    public static function unpackedSize(string $file, int $limit): int
    {
        $data  = file_get_contents($file);
        $total = 0;
        $pos   = 0;
        // Each stream starts after the keyword "stream" and a line break (CR LF or LF) and ends
        // before "endstream"
        while (($start = strpos($data, 'stream', $pos)) !== false) {
            $begin = $start + 6;
            if (substr($data, $begin, 2) === "\r\n") {
                $begin += 2;
            } elseif (($data[$begin] ?? '') === "\n") {
                $begin++;
            } else {
                // "stream" inside "endstream" or other text: go on after it
                $pos = $begin;
                continue;
            }
            $end = strpos($data, 'endstream', $begin);
            if ($end === false) {
                break;
            }
            $packed = substr($data, $begin, $end - $begin);
            // Dictionary of the stream: the text since the last "<<" before "stream"
            $dict_start = strrpos(substr($data, max(0, $start - 4096), min($start, 4096)), '<<');
            $dict       = $dict_start === false ? '' : substr($data, max(0, $start - 4096) + $dict_start, 4096);
            if (str_contains($dict, '/LZWDecode') || preg_match('#/LZW\b#', $dict) === 1) {
                // Counted with the most it could grow to, limited by the parser's own stream limit
                $total += min(strlen($packed) * self::LZW_FACTOR, self::PHP_PARSER_STREAM_LIMIT);
            } elseif (str_contains($dict, '/RunLengthDecode') || preg_match('#/RL\b#', $dict) === 1) {
                $total += min(strlen($packed) * self::RUNLENGTH_FACTOR, self::PHP_PARSER_STREAM_LIMIT);
            } else {
                $total += self::inflatedLength($packed, $limit - $total);
            }
            if ($total > $limit) {
                return $total;
            }
            $pos = $end + 9;
        }

        return $total;
    }

    /**
     * Length of zlib data once unpacked, counted in pieces of 1 KB of input (one piece can unpack
     * to at most about 1 MB), stopping above $limit; the packed length if it is no zlib data.
     */
    private static function inflatedLength(string $packed, int $limit): int
    {
        $length = 0;
        try {
            $context = inflate_init(ZLIB_ENCODING_DEFLATE);
            foreach (str_split($packed, 1024) as $piece) {
                $length += strlen(inflate_add($context, $piece, ZLIB_SYNC_FLUSH));
                if ($length > $limit) {
                    break;
                }
            }
        } catch (Throwable) {
            // No zlib data (another filter, an image): it counts with its packed size
            return max($length, strlen($packed));
        }

        return $length;
    }

    /**
     * Bytes of a PHP size setting such as "256M", "1G" or "-1" (no limit: 0 is returned).
     */
    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;
        // Last character: K, M or G (any case) multiplies by 1024, 1024² or 1024³
        return match (strtoupper(substr($value, -1))) {
            'G'     => $number * 1024 ** 3,
            'M'     => $number * 1024 ** 2,
            'K'     => $number * 1024,
            default => $number,
        };
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
