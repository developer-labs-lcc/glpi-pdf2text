<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.1.0
 * @created   2026-11-15
 * @changed   2026-11-15
 */

namespace GlpiPlugin\Pdf2text;

use CommonDBTM;
use CommonITILObject;
use Config;
use DBConnection;
use DBmysql;
use ITILFollowup;

/**
 * Remembers which files were already read for which ticket, change or problem, so that the
 * same file attached again (e.g. a reply mail quoting the PDF of the first mail) does not add
 * its text a second time.
 *
 * Files are told apart by their SHA-1 checksum (GLPI stores it for every document), not by their
 * name: a new file with the same name (e.g. this month's "report.pdf") is a new file.
 *
 * Followups written before this table existed (installations before version 1.0.0 was final)
 * are recognised by their content instead, see isDone().
 */
final class ProcessedFiles
{
    public const TABLE = 'glpi_plugin_pdf2text_processedfiles';

    /** Configuration value (context Settings::CONTEXT): when the table was created. */
    public const SINCE_KEY = 'tracking_since';

    /**
     * Creates the table (if missing) and stores when the tracking started.
     */
    public static function install(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        // false: ask the database, not GLPI's cache of table names
        if (!$DB->tableExists(self::TABLE, false)) {
            $charset   = DBConnection::getDefaultCharset();
            $collation = DBConnection::getDefaultCollation();
            $sign      = DBConnection::getDefaultPrimaryKeySignOption();

            // One row per file and ITIL object. itilfollowups_id = the followup with the text,
            // 0 = the file was read but has no text (e.g. a scanned PDF).
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE . "` (
                    `id` int {$sign} NOT NULL AUTO_INCREMENT,
                    `itemtype` varchar(100) NOT NULL DEFAULT '',
                    `items_id` int {$sign} NOT NULL DEFAULT '0',
                    `sha1sum` char(40) NOT NULL DEFAULT '',
                    `documents_id` int {$sign} NOT NULL DEFAULT '0',
                    `itilfollowups_id` int {$sign} NOT NULL DEFAULT '0',
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`itemtype`, `items_id`, `sha1sum`),
                    KEY `documents_id` (`documents_id`),
                    KEY `itilfollowups_id` (`itilfollowups_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
            );
        }

        // Kept on a new installation of the same database: followups of before stay "old" ones.
        if (Config::getConfigurationValue(Settings::CONTEXT, self::SINCE_KEY) === null) {
            Config::setConfigurationValues(Settings::CONTEXT, [self::SINCE_KEY => date('Y-m-d H:i:s')]);
        }
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->dropTable(self::TABLE, true);
    }

    /**
     * Whether the file with this checksum was already read for this ITIL object.
     *
     * @param string $filename name of the file, to recognise followups written before the table
     */
    public static function isDone(CommonITILObject $parent, string $sha1sum, string $filename): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        // Plugin files updated, but the update in Setup > Plugins not run yet: no table
        if ($DB->tableExists(self::TABLE)) {
            $rows = $DB->request([
                'SELECT' => ['id'],
                'FROM'   => self::TABLE,
                'WHERE'  => ['itemtype' => $parent::class, 'items_id' => $parent->getID(), 'sha1sum' => $sha1sum],
                'LIMIT'  => 1,
            ]);
            if (count($rows) > 0) {
                return true;
            }
        }

        return self::hasOldFollowup($parent, $filename);
    }

    /**
     * Reserves the file for the ITIL object before it is read, so that two processes reading the
     * same file at the same time (e.g. the mail collector and a click on the button) do not both
     * add a followup: the row is added only if there is none for this file yet, in one step of the
     * database (the unique key decides). "ON DUPLICATE KEY UPDATE id = id" changes nothing on an
     * existing row (0 affected rows); unlike INSERT IGNORE it causes no SQL warning, which GLPI
     * would log (and whose check resets the number of affected rows).
     *
     * @return int|null id of the new row; null if a row exists already (the file is read or
     *                  being read); 0 if the table does not exist yet (update not run)
     */
    public static function reserve(CommonITILObject $parent, string $sha1sum, int $documents_id): ?int
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }
        $DB->doQuery(sprintf(
            'INSERT INTO %s (%s, %s, %s, %s, %s, %s) VALUES (%s, %d, %s, %d, 0, %s) ON DUPLICATE KEY UPDATE %s = %s',
            DBmysql::quoteName(self::TABLE),
            DBmysql::quoteName('itemtype'),
            DBmysql::quoteName('items_id'),
            DBmysql::quoteName('sha1sum'),
            DBmysql::quoteName('documents_id'),
            DBmysql::quoteName('itilfollowups_id'),
            DBmysql::quoteName('date_creation'),
            Cast::string(DBmysql::quoteValue($parent::class)),
            $parent->getID(),
            Cast::string(DBmysql::quoteValue($sha1sum)),
            $documents_id,
            Cast::string(DBmysql::quoteValue(date('Y-m-d H:i:s'))),
            DBmysql::quoteName('id'),
            DBmysql::quoteName('id'),
        ));

        // getAffectedRows() since GLPI 12 (affectedRows() is deprecated there), affectedRows() in
        // GLPI 11: called by name, so that the code works with both
        $method   = method_exists($DB, 'getAffectedRows') ? 'getAffectedRows' : 'affectedRows';
        $affected = Cast::int($DB->$method());

        return $affected > 0 ? Cast::int($DB->insertId()) : null;
    }

    /**
     * Removes a reservation of reserve() again (the file could not be read or no followup could be
     * added: a later attempt may try again).
     */
    public static function release(int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($id > 0 && $DB->tableExists(self::TABLE)) {
            $DB->delete(self::TABLE, ['id' => $id]);
        }
    }

    /**
     * Notes that the file was read for the ITIL object (followup id 0: it had no text).
     */
    public static function remember(CommonITILObject $parent, string $sha1sum, int $documents_id, int $followups_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }
        // The row reserved before (see reserve()), or the row of an earlier reading when the text
        // is added again on request, is updated
        $DB->updateOrInsert(
            self::TABLE,
            ['documents_id' => $documents_id, 'itilfollowups_id' => $followups_id, 'date_creation' => date('Y-m-d H:i:s')],
            ['itemtype' => $parent::class, 'items_id' => $parent->getID(), 'sha1sum' => $sha1sum],
        );
    }

    /**
     * Hook "item_purge" of tickets, changes and problems: their rows are not needed any more.
     */
    public static function onItemPurge(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($DB->tableExists(self::TABLE)) {
            $DB->delete(self::TABLE, ['itemtype' => $item::class, 'items_id' => $item->getID()]);
        }
    }

    /**
     * Hook "item_purge" of followups: when the followup with the text is deleted, the file
     * counts as not read, so attaching it again (or processing the ticket again) adds the text
     * again.
     */
    public static function onFollowupPurge(CommonDBTM $followup): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($DB->tableExists(self::TABLE) && $followup->getID() > 0) {
            $DB->delete(self::TABLE, ['itilfollowups_id' => $followup->getID()]);
        }
    }

    /**
     * Whether a followup written before the tracking started already holds the text of a file
     * with this name: a followup of the plugin has the file name in quotes in its heading and the
     * text in a <pre> block (see DocumentHandler::buildContent()), in English or German.
     * Newer followups are not looked at: for them the table knows, and a new file with an old
     * name must not count as read.
     */
    private static function hasOldFollowup(CommonITILObject $parent, string $filename): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $since = Config::getConfigurationValue(Settings::CONTEXT, self::SINCE_KEY);
        if ($filename === '' || !is_string($since) || $since === '') {
            return false;
        }

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => [
                'itemtype'      => $parent::class,
                'items_id'      => $parent->getID(),
                'date_creation' => ['<', $since],
                ['content' => ['LIKE', '%<pre>%']],
                // The name in quotes as in the heading, so that "a.pdf" does not match "data.pdf":
                // "&quot;name&quot;" (English, the quotes HTML-escaped) or "„name“" (German); the
                // LIKE wildcards % and _ (and the escape character \) of the name are escaped
                ['OR' => [
                    ['content' => ['LIKE', '%&quot;' . addcslashes(htmlescape($filename), '%_\\') . '&quot;%']],
                    ['content' => ['LIKE', '%„' . addcslashes(htmlescape($filename), '%_\\') . '“%']],
                ]],
            ],
            'LIMIT' => 1,
        ]);

        return count($rows) > 0;
    }
}
