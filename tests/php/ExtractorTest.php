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
