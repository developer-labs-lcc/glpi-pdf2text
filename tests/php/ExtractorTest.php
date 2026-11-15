<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.2.0
 * @created   2026-10-22
 * @changed   2026-11-15
 */

// Text extraction without GLPI: pdftotext (if installed) and the PHP parser, scanned PDF,
// unreadable file, normalisation and truncation. Only needs the plugin's vendor/ directory
// ("composer install" with dev packages), so it also runs on a host without GLPI:
// php tests/php/ExtractorTest.php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The Safe library is part of GLPI; outside of GLPI it comes from the dev packages. If the plugin
// was installed without dev packages inside a GLPI tree, use GLPI's copy.
$glpi_autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (!function_exists('Safe\\filesize') && is_readable($glpi_autoload)) {
    require $glpi_autoload;
}

use GlpiPlugin\Pdf2text\OfficeTextExtractor;
use GlpiPlugin\Pdf2text\PdfTextExtractor;

$fail  = 0;
$check = static function (string $label, bool $cond, string $extra = '') use (&$fail): void {
    echo ($cond ? '  OK   ' : '  FAIL ') . $label . ($extra !== '' ? "  -> $extra" : '') . "\n";
    $fail += $cond ? 0 : 1;
};
$fixtures = dirname(__DIR__) . '/fixtures/';

// A configured path that does not exist forces the PHP parser.
$methods = ['PHP parser' => new PdfTextExtractor('/nonexistent/pdftotext')];
$auto    = new PdfTextExtractor();
if (str_starts_with($auto->getMethod(), 'pdftotext')) {
    $methods['pdftotext'] = $auto;
} else {
    echo "  (pdftotext not installed here – only the PHP parser is tested)\n";
}

foreach ($methods as $name => $extractor) {
    echo "== $name: " . $extractor->getMethod() . "\n";
    $text = $extractor->extract($fixtures . 'change.pdf');
    $check('heading found', str_contains($text, 'Change Notification CN-2026-0815'));
    $check('umlauts kept (UTF-8)', str_contains($text, 'Kärnten') && str_contains($text, 'Zürich'), mb_substr($text, 0, 120));
    $check('table cell found', str_contains($text, 'Core router R1'));
    $check('search token found', str_contains($text, 'ZEBRA-4711'));
    $check('no page break characters', !str_contains($text, "\f"));
    $check('scanned PDF gives empty text', $extractor->extract($fixtures . 'scan.pdf') === '');

    $broken = tempnam(sys_get_temp_dir(), 'pdf2text');
    file_put_contents($broken, "%PDF-1.4\nthis is not a real PDF\n");
    try {
        $extractor->extract($broken);
        $check('broken PDF throws an exception', false);
    } catch (RuntimeException $e) {
        $check('broken PDF throws an exception', true, $e->getMessage());
    } finally {
        unlink($broken);
    }
}

// Word and OpenDocument: both fixtures hold the same text, plus parts that must not be read
// (deleted text of tracked changes, a field code, a comment, a paragraph in the styles)
$office = new OfficeTextExtractor();
foreach ([OfficeTextExtractor::DOCX, OfficeTextExtractor::ODT] as $format) {
    echo "== $format\n";
    $text = $office->extract($fixtures . 'change.' . $format, $format);
    $check('heading found', str_contains($text, 'Change Notification CN-2026-0815'));
    $check('umlauts kept (UTF-8)', str_contains($text, 'Kärnten') && str_contains($text, 'Zürich'));
    $check('tab and line break kept', str_contains($text, "Key\tValue\nsecond line"), json_encode($text, JSON_UNESCAPED_UNICODE) ?: '');
    $check('table row on one line, cells separated by a tab', str_contains($text, "Core router R1\tOutage 15 min"));
    $check('search token found', str_contains($text, 'OKAPI-0815'));
    $check('deleted text left out', !str_contains($text, 'DELETED-WORD'));
    $check('no field codes, comments or styles', !str_contains($text, 'FIELDCODE') && !str_contains($text, 'COMMENT-TEXT') && !str_contains($text, 'STYLE-NOT-TEXT'));
    $check('no footnotes and no generated table of contents', !str_contains($text, 'FOOTNOTE-TEXT') && !str_contains($text, 'TOC-ENTRY'));
    $check('paragraphs separated by an empty line', str_contains($text, "Zürich\n\nKey"));
    $check(
        'bullets, nested numbered list, numbered list starting at 1',
        str_contains($text, "• Bullet one\n  1. Sub step A\n  2. Sub step B\n• Bullet two\n") && str_contains($text, "1. Numbered one\n2. Numbered two\n"),
        $text,
    );

    $blocks = $office->extractBlocks($fixtures . 'change.' . $format, $format);
    [$html, $short] = OfficeTextExtractor::toHtml($blocks, 100000);
    $check('HTML: headings', str_contains($html, '<h3>Change Notification CN-2026-0815</h3>') && str_contains($html, '<h4>Steps</h4>'), $html);
    $check('HTML: nested lists', str_contains($html, '<ul><li>Bullet one<ol><li>Sub step A</li><li>Sub step B</li></ol></li><li>Bullet two</li></ul>')
        && str_contains($html, '<ol><li>Numbered one</li><li>Numbered two</li></ol>'), $html);
    $check('list interrupted by a paragraph counts on (3.)', str_contains($text, "Between the lists\n\n3. Numbered three")
        && str_contains($html, '<p>Between the lists</p><ol start="3"><li>Numbered three</li></ol>'));
    $check('text box in a table cell read once', substr_count($text, 'CELLBOX') === 1 && str_contains($text, "Cell with box: CELLBOX\tx"), $text);
    $check('HTML: table, escaped text, line break', str_contains($html, '<td>Core router R1</td><td>Outage 15 min</td>') && str_contains($html, 'Kärnten &amp; Zürich') && str_contains($html, 'Value<br>second line'));
    $check('HTML: not shortened', !$short);
    [$html, $short] = OfficeTextExtractor::toHtml($blocks, 80);
    $check('HTML: shortened to the maximum length, lists closed', $short && !str_contains($html, 'OKAPI') && substr_count($html, '<ul>') === substr_count($html, '</ul>'), $html);
}
$check('DOCX: text box read once (no copy for old programs)', substr_count($office->extract($fixtures . 'change.docx', 'docx'), 'Box text') === 1);
$odt_text = $office->extract($fixtures . 'change.odt', 'odt');
$check('ODT: list header is a paragraph, not counted', str_contains($odt_text, "LIST-HEADER\n\n1. Numbered one"), $odt_text);
try {
    PdfTextExtractor::ensureMemory(PHP_INT_MAX >> 2);
    $check('not enough memory: exception instead of a fatal error', ini_get('memory_limit') === '-1');
} catch (RuntimeException $e) {
    $check('not enough memory: exception instead of a fatal error', true, $e->getMessage());
}
$check('ODT: several spaces (text:s) kept', str_contains($office->extract($fixtures . 'change.odt', 'odt'), 'A   B kept'));

$not_zip = tempnam(sys_get_temp_dir(), 'pdf2text');
file_put_contents($not_zip, "this is not a ZIP archive\n");
try {
    $office->extract($not_zip, OfficeTextExtractor::DOCX);
    $check('file that is no ZIP archive throws an exception', false);
} catch (RuntimeException $e) {
    $check('file that is no ZIP archive throws an exception', true, $e->getMessage());
} finally {
    unlink($not_zip);
}
try {
    // The ODT has no word/document.xml
    $office->extract($fixtures . 'change.odt', OfficeTextExtractor::DOCX);
    $check('ZIP without the text of the format throws an exception', false);
} catch (RuntimeException $e) {
    $check('ZIP without the text of the format throws an exception', true, $e->getMessage());
}
$xxe = tempnam(sys_get_temp_dir(), 'pdf2text');
$zip = new ZipArchive();
$zip->open($xxe, ZipArchive::OVERWRITE);
// External entity pointing to a file of the server: must not be read (XXE)
$zip->addFromString('word/document.xml', '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file:///etc/hostname">]>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>A&x;B</w:t></w:r></w:p></w:body></w:document>');
$zip->close();
try {
    $xxe_text = $office->extract($xxe, OfficeTextExtractor::DOCX);
} catch (RuntimeException $e) {
    $xxe_text = '';
}
$hostname = is_readable('/etc/hostname') ? trim((string) file_get_contents('/etc/hostname')) : '';
$check('external entities are not loaded (XXE)', $hostname === '' || !str_contains($xxe_text, $hostname), $xxe_text);
unlink($xxe);

echo "== helpers\n";
try {
    $auto->extract($fixtures . 'missing.pdf');
    $check('missing file throws an exception', false);
} catch (RuntimeException) {
    $check('missing file throws an exception', true);
}
$check(
    'normalise: CRLF, form feed, trailing spaces, empty lines',
    PdfTextExtractor::normalise("\nA  \r\nB\f\n\n\n\nC\t\n\n") === "A\nB\n\nC",
);
$check('truncate: short text unchanged', PdfTextExtractor::truncate('äöü', 3) === ['äöü', false]);
$check('truncate: counts characters, not bytes', PdfTextExtractor::truncate('äöüß', 2) === ['äö', true]);

echo "FAILURES: $fail\n";
exit($fail > 0 ? 1 : 0);
