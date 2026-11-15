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

namespace GlpiPlugin\Pdf2text;

/**
 * Type-safe conversion of untyped values (fields of GLPI items, configuration values).
 *
 * GLPI stores item fields and settings as untyped arrays; these helpers turn them into
 * int or string without silently converting arrays or objects.
 */
final class Cast
{
    /**
     * Integer value of a number or numeric string; anything else gives $default.
     */
    public static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * String value of a scalar (string, number, bool); anything else gives ''.
     */
    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
