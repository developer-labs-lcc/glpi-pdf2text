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

use GlpiPlugin\Pdf2text\Settings;

function plugin_pdf2text_install(): bool
{
    // Store the defaults once, without overwriting values of an earlier installation.
    Settings::set(Settings::getStored() + Settings::DEFAULTS);

    return true;
}

function plugin_pdf2text_uninstall(): bool
{
    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);

    return true;
}
