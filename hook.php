<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 */

use GlpiPlugin\Pdftext\Settings;

function plugin_pdftext_install(): bool
{
    // Store the defaults once, without overwriting values of an earlier installation.
    Settings::set(Config::getConfigurationValues(Settings::CONTEXT) + Settings::DEFAULTS);

    return true;
}

function plugin_pdftext_uninstall(): bool
{
    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);

    return true;
}
