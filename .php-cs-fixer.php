<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.2
 * @created   2026-10-23
 * @changed   2026-11-15
 */

// Code style of GLPI plugins. Like Teclib's plugin template, this needs the plugin to be placed
// in the plugins/ directory of a GLPI development checkout, which provides the rule set.

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$baseline_file = __DIR__ . '/../../PluginsPhpCsFixer.php';
if (!file_exists($baseline_file)) {
    throw new RuntimeException(sprintf(
        'Unable to find "%s". Running php-cs-fixer on a plugin requires a GLPI development checkout that ships PluginsPhpCsFixer.php.',
        $baseline_file,
    ));
}

$baseline = require $baseline_file;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude(['vendor', 'var'])
    ->name('*.php');

/** @var Config $config */
$config = $baseline($finder);

return $config->setCacheFile(__DIR__ . '/var/php-cs-fixer/.php-cs-fixer.cache');
