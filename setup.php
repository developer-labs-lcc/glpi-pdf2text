<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Pdftext\DocumentHandler;

/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_PDFTEXT_VERSION', '1.0.0');

// Minimal GLPI version, inclusive. No upper bound on purpose.
/** @phpstan-ignore theCodingMachineSafe.function (safe to assume this isn't already defined) */
define('PLUGIN_PDFTEXT_MIN_GLPI', '11.0.0');

// GLPI only autoloads the plugin's own classes (src/); libraries installed with Composer
// (the PDF parser) need the plugin's vendor autoloader. Missing vendor/ is reported by
// plugin_pdftext_check_prerequisites().
if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    include_once __DIR__ . '/vendor/autoload.php';
}

function plugin_init_pdftext(): void
{
    /** @var array<string, array<string, mixed>> $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    if (!Plugin::isPluginActive('pdftext')) {
        return;
    }

    // Every attachment ends up as a link between a document and an item (ticket, followup, ...).
    // Reacting to these links covers the mail collector, the web form, the API and mass imports.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['pdftext'] = [
        Document_Item::class => [DocumentHandler::class, 'onDocumentItemAdd'],
    ];

    // Setup > Plugins > PDF2Text
    if (Session::haveRight(Config::$rightname, UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['pdftext'] = 'config';
    }
}

/**
 * @return array<string, mixed>
 */
function plugin_version_pdftext(): array
{
    return [
        'name'         => 'PDF2Text',
        'version'      => PLUGIN_PDFTEXT_VERSION,
        'author'       => 'AI & Developer Labs LLC',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://developer-labs.com/en/glpi-development/',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PDFTEXT_MIN_GLPI,
            ],
            'php' => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_pdftext_check_prerequisites(): bool
{
    // The bundled PDF parser is the fallback when pdftotext is not installed.
    if (!is_readable(__DIR__ . '/vendor/autoload.php')) {
        echo __('Run "composer install --no-dev" in the plugin directory first.', 'pdftext');
        return false;
    }

    return true;
}

function plugin_pdftext_check_config(bool $verbose = false): bool
{
    return true;
}
