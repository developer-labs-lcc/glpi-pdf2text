<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.1.0
 * @created   2026-10-22
 * @changed   2026-11-15
 */

use GlpiPlugin\Pdf2text\MassiveActions;
use GlpiPlugin\Pdf2text\ProcessedFiles;
use GlpiPlugin\Pdf2text\Settings;

function plugin_pdf2text_install(): bool
{
    // Store the defaults once, without overwriting values of an earlier installation.
    Settings::set(Settings::getStored() + Settings::DEFAULTS);
    // Table of the files read per ticket, change or problem (against duplicate followups)
    ProcessedFiles::install();

    return true;
}

function plugin_pdf2text_uninstall(): bool
{
    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);
    ProcessedFiles::uninstall();

    return true;
}

/**
 * Massive actions of the plugin for the list of the given type (hook "use_massive_action" in
 * setup.php): "Copy the text of the attachments" for tickets, changes and problems.
 *
 * @return array<string, string>
 */
function plugin_pdf2text_MassiveActions(string $itemtype): array
{
    return MassiveActions::forItemtype($itemtype);
}
