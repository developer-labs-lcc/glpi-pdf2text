<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.3
 * @created   2026-10-23
 * @changed   2026-11-15
 */

// Configuration page: rendering, CSRF token field only where GLPI uses tokens (GLPI 11, not 12),
// saving with validation (also of the file types), access check. The plugin settings are restored at the end.

require __DIR__ . '/bootstrap.php';

use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Pdf2text\Controller\ConfigController;
use GlpiPlugin\Pdf2text\Settings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

$original_settings = [];
foreach (array_keys(Settings::DEFAULTS) as $key) {
    $original_settings[$key] = Settings::get($key);
}
register_shutdown_function(static fn() => Settings::set($original_settings));
$controller = new ConfigController();

section('page');
$GLOBALS['HEADER_LOADED'] = false;
ob_start();
$response = $controller->config(Request::create('/plugins/pdf2text/config', 'GET'));
$html = ob_get_clean() . $response->getContent();
check('page rendered', $response->getStatusCode() === 200 && str_contains($html, 'name="max_chars"'));
check('file types offered', str_contains($html, 'name="type_docx"') && str_contains($html, 'name="type_odt"') && str_contains($html, 'name="type_pdf"'));
check('console command named', str_contains($html, 'plugins:pdf2text:process'));
$uses_token = version_compare(GLPI_VERSION, '12.0.0-dev', '<');
// value="..." with at least one character: a real token
$has_token = preg_match('/name="_glpi_csrf_token" value="[^"]+"/', $html) === 1;
check('CSRF token field ' . ($uses_token ? 'present (GLPI 11)' : 'absent (GLPI 12)'), $has_token === $uses_token, GLPI_VERSION);

section('saving');
$response = $controller->config(Request::create('/plugins/pdf2text/config', 'POST', [
    'on_ticket' => '1', 'on_change' => '0', 'on_problem' => '1',
    'is_private' => '0', 'max_chars' => '5', 'pdftotext_path' => 'relative/path',
    'type_pdf' => '1', 'type_docx' => '0', 'type_odt' => '1',
]));
Settings::reset();
check('redirect after saving', $response instanceof RedirectResponse);
check('values saved', Settings::get('on_change') == 0 && Settings::get('is_private') == 0);
check('file types saved', Settings::get('type_docx') == 0 && Settings::get('type_odt') == 1 && Settings::get('type_pdf') == 1);
check('maximum length kept within limits', (int) Settings::get('max_chars') === 1000);
check('relative pdftotext path rejected', Settings::get('pdftotext_path') === '');
// Only a program named pdftotext: another program would run for every attachment
$controller->config(Request::create('/plugins/pdf2text/config', 'POST', ['pdftotext_path' => '/bin/sh']));
Settings::reset();
check('path to another program rejected', Settings::get('pdftotext_path') === '');
$controller->config(Request::create('/plugins/pdf2text/config', 'POST', ['pdftotext_path' => '/opt/poppler/bin/pdftotext']));
Settings::reset();
check('absolute path to pdftotext kept', Settings::get('pdftotext_path') === '/opt/poppler/bin/pdftotext');

section('access');
$rights = $_SESSION['glpiactiveprofile']['config'];
$_SESSION['glpiactiveprofile']['config'] = READ;
try {
    $controller->config(Request::create('/plugins/pdf2text/config', 'GET'));
    check('without the right to change the configuration: denied', false);
} catch (AccessDeniedHttpException) {
    check('without the right to change the configuration: denied', true);
}
$_SESSION['glpiactiveprofile']['config'] = $rights;

finish();
