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

use Glpi\Plugin\Hooks;
use GlpiPlugin\Pdf2text\AttachmentButton;
use GlpiPlugin\Pdf2text\Cast;
use GlpiPlugin\Pdf2text\DocumentHandler;
use GlpiPlugin\Pdf2text\ProcessedFiles;

use function Safe\filemtime;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_PDF2TEXT_VERSION', '1.0.1');

// Minimal GLPI version, inclusive. No upper bound on purpose (tested with GLPI 11 and 12).
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_PDF2TEXT_MIN_GLPI', '11.0.0');

// GLPI only autoloads the plugin's own classes (src/); libraries installed with Composer
// (the PDF parser) need the plugin's vendor autoloader. Missing vendor/ is reported by
// plugin_pdf2text_check_prerequisites().
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    include_once __DIR__ . '/vendor/autoload.php';
}

function plugin_init_pdf2text(): void
{
    /** @var array<string, array<string, mixed>> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('pdf2text')) {
        return;
    }

    // Every attachment ends up as a link between a document and an item (ticket, followup, ...).
    // Reacting to these links covers the mail collector, the web form, the API and mass imports.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['pdf2text'] = [
        Document_Item::class => [DocumentHandler::class, 'onDocumentItemAdd'],
    ];

    // Which files were read for which ticket, change or problem (see ProcessedFiles): rows of
    // deleted objects are removed; a deleted text followup lets the file be read again.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['pdf2text'] = [
        Ticket::class       => [ProcessedFiles::class, 'onItemPurge'],
        Change::class       => [ProcessedFiles::class, 'onItemPurge'],
        Problem::class      => [ProcessedFiles::class, 'onItemPurge'],
        ITILFollowup::class => [ProcessedFiles::class, 'onFollowupPurge'],
        // A file taken off the ticket: its text is made private (see ProcessedFiles::hideTexts())
        Document_Item::class => [ProcessedFiles::class, 'onDocumentUnlink'],
    ];
    // A followup or task made private: the texts of its files are made private as well
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['pdf2text'] = [
        ITILFollowup::class => [ProcessedFiles::class, 'onSourceUpdate'],
        TicketTask::class   => [ProcessedFiles::class, 'onSourceUpdate'],
        ChangeTask::class   => [ProcessedFiles::class, 'onSourceUpdate'],
        ProblemTask::class  => [ProcessedFiles::class, 'onSourceUpdate'],
    ];

    // Massive action "Copy the text of the attachments" in the lists of tickets, changes and
    // problems (actions: plugin_pdf2text_MassiveActions() in hook.php, see MassiveActions)
    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['pdf2text'] = true;

    // Button "Copy the text into a followup" at the attachments in the timeline (see
    // AttachmentButton): only on the form pages of tickets, changes and problems, for users who
    // may add a followup there, and not for AJAX requests (they print no page header).
    $request_uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
    if (Session::getLoginUserID() !== false && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;
        $tag = AttachmentButton::headerTag($request_uri, Cast::string($CFG_GLPI['root_doc'] ?? ''));
        if ($tag !== null) {
            $PLUGIN_HOOKS[Hooks::ADD_HEADER_TAG]['pdf2text'] = [$tag];
            // The parameter changes with the file, so browsers do not keep an old version
            $script = __DIR__ . '/public/js/attachments.js';
            $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['pdf2text'] = ['js/attachments.js?h=' . substr(md5(PLUGIN_PDF2TEXT_VERSION . (is_file($script) ? filemtime($script) : '')), 0, 8)];
        }
    }

    // Setup > Plugins > PDF2Text
    if (Session::haveRight(Config::$rightname, UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['pdf2text'] = 'config';
    }
}

/**
 * @return array<string, mixed>
 */
function plugin_version_pdf2text(): array
{
    return [
        'name'         => 'PDF2Text',
        'version'      => PLUGIN_PDF2TEXT_VERSION,
        'author'       => 'AI & Developer Labs LLC',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://developer-labs.com/en/glpi-development/',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PDF2TEXT_MIN_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_pdf2text_check_prerequisites(): bool
{
    // The bundled PDF parser is the fallback when pdftotext is not installed.
    if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
        echo __('Run "composer install --no-dev" in the plugin directory first.', 'pdf2text');
        return false;
    }

    return true;
}

function plugin_pdf2text_check_config(bool $verbose = false): bool
{
    return true;
}
