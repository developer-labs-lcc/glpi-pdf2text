<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 */

namespace GlpiPlugin\Pdftext;

use Change;
use Config;
use Problem;
use Ticket;

/**
 * Plugin settings, stored with GLPI's configuration API (context "plugin:pdftext").
 * Only users with the right to change the GLPI configuration can edit them.
 */
final class Settings
{
    public const CONTEXT = 'plugin:pdftext';

    /** ITIL object types the text can be added to (key = setting name). */
    public const ITEMTYPES = [
        'on_ticket'  => Ticket::class,
        'on_change'  => Change::class,
        'on_problem' => Problem::class,
    ];

    public const DEFAULTS = [
        'on_ticket'      => 1,
        'on_change'      => 1,
        'on_problem'     => 1,
        // The extracted text is added as a private followup (only visible to technicians).
        'is_private'     => 1,
        // Longer texts are cut off; the full text stays in the PDF.
        'max_chars'      => 20000,
        // Path of the pdftotext program (poppler-utils). Empty = search it in the usual places.
        'pdftotext_path' => '',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    public static function get(string $key): mixed
    {
        if (self::$cache === null) {
            self::$cache = self::getStored() + self::DEFAULTS;
        }

        return self::$cache[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public static function getInt(string $key, int $min, int $max): int
    {
        return max($min, min($max, Cast::int(self::get($key))));
    }

    /**
     * Values stored in the GLPI configuration (without defaults).
     *
     * @return array<string, mixed>
     */
    public static function getStored(): array
    {
        $values = [];
        foreach (Config::getConfigurationValues(self::CONTEXT) as $name => $value) {
            $values[(string) $name] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function set(array $values): void
    {
        Config::setConfigurationValues(self::CONTEXT, array_intersect_key($values, self::DEFAULTS));
        self::$cache = null;
    }

    /**
     * Whether extracted text should be added to items of the given ITIL type.
     */
    public static function isEnabledFor(string $itemtype): bool
    {
        $key = array_search($itemtype, self::ITEMTYPES, true);

        return $key !== false && (bool) self::get($key);
    }

    public static function reset(): void
    {
        self::$cache = null;
    }
}
