<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   2.0.0
 * @created   2026-11-15
 * @changed   2026-11-19
 */

namespace GlpiPlugin\Pdf2text;

use CommonDBTM;
use CommonITILObject;
use Config;
use DBConnection;
use DBmysql;
use Document;
use ITILFollowup;
use Migration;
use Throwable;
use Toolbox;

use function Safe\strtotime;

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

    /** States of a row (column "status") */
    public const RESERVED = 'reserved'; // being read right now (see reserve())
    public const EMPTY    = 'empty';    // read, no text in it (e.g. a scanned PDF)
    public const ADDED    = 'added';    // read, its text is in the followup itilfollowups_id

    /**
     * A reservation older than this (seconds) is taken as left over by a process that died while
     * reading (out of memory, time limit, killed): the file may be read again.
     */
    public const STALE_AFTER = 900;

    /**
     * Creates the table (if missing), adds columns of newer versions and stores when the
     * tracking started.
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

            // One row per file and ITIL object. itilfollowups_id = the followup with the text
            // (0 while reserved or if the file has no text); status: see the constants above.
            $DB->doQuery(
                "CREATE TABLE `" . self::TABLE . "` (
                    `id` int {$sign} NOT NULL AUTO_INCREMENT,
                    `itemtype` varchar(100) NOT NULL DEFAULT '',
                    `items_id` int {$sign} NOT NULL DEFAULT '0',
                    `sha1sum` char(40) NOT NULL DEFAULT '',
                    `documents_id` int {$sign} NOT NULL DEFAULT '0',
                    `itilfollowups_id` int {$sign} NOT NULL DEFAULT '0',
                    `status` varchar(10) NOT NULL DEFAULT 'added',
                    `date_creation` timestamp NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unicity` (`itemtype`, `items_id`, `sha1sum`),
                    KEY `documents_id` (`documents_id`),
                    KEY `itilfollowups_id` (`itilfollowups_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC",
            );
        } elseif (!$DB->fieldExists(self::TABLE, 'status', false)) {
            // Update from 1.0.0: rows without followup were files without text
            $migration = new Migration(PLUGIN_PDF2TEXT_VERSION);
            $migration->addField(self::TABLE, 'status', 'string', ['after' => 'itilfollowups_id', 'value' => self::ADDED]);
            $migration->executeMigration();
            $DB->update(self::TABLE, ['status' => self::EMPTY], ['itilfollowups_id' => 0]);
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
     * Whether the file with this checksum was already read for this ITIL object: a row in the
     * table (not a left over reservation), else a followup of the plugin with the checksum of the
     * file in its heading (any version since 1.0.1, any language, also copied by a merge of
     * tickets), else a followup of an earlier version naming the file (see hasOldFollowup()).
     *
     * @param string $filename name of the file, to recognise followups of earlier versions
     */
    public static function isDone(CommonITILObject $parent, string $sha1sum, string $filename): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        // Plugin files updated, but the update in Setup > Plugins not run yet: no table
        if ($DB->tableExists(self::TABLE)) {
            $row = $DB->request([
                'FROM'  => self::TABLE,
                'WHERE' => self::key($parent, $sha1sum),
                'LIMIT' => 1,
            ])->current();
            if (is_array($row) && !self::isStale($row)) {
                return true;
            }
        }

        return self::hasMarkedFollowup($parent, $sha1sum) || self::hasOldFollowup($parent, $filename);
    }

    /**
     * Reserves the file for the ITIL object before it is read, so that two processes reading the
     * same file at the same time (e.g. the mail collector and a click on the button) do not both
     * add a followup. The unique key of the table decides: the row is added with GLPI's query
     * builder; if a row for this file exists already, the database refuses the insert. A left over
     * reservation (see STALE_AFTER) is taken over instead.
     *
     * @return int|null id of the reserved row; null if a row exists already (the file is read or
     *                  being read); 0 if the table does not exist yet (update not run)
     */
    public static function reserve(CommonITILObject $parent, string $sha1sum, int $documents_id): ?int
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return 0;
        }
        $key = self::key($parent, $sha1sum);
        try {
            $DB->insert(self::TABLE, $key + [
                'documents_id'     => $documents_id,
                'itilfollowups_id' => 0,
                'status'           => self::RESERVED,
                'date_creation'    => date('Y-m-d H:i:s'),
            ]);

            return Cast::int($DB->insertId());
        } catch (Throwable $e) {
            $row = $DB->request(['FROM' => self::TABLE, 'WHERE' => $key, 'LIMIT' => 1])->current();
            if (!is_array($row)) {
                // Not a duplicate key: passed on
                throw $e;
            }
            if (!self::isStale($row)) {
                return null;
            }
            // Left over reservation: taken over, but only if no other process took it in between
            // (the update changes the row only while it is still the old reservation)
            $DB->update(
                self::TABLE,
                ['documents_id' => $documents_id, 'date_creation' => date('Y-m-d H:i:s')],
                ['id' => $row['id'], 'status' => self::RESERVED, 'date_creation' => $row['date_creation']],
            );

            return self::affectedRows() > 0 ? Cast::int($row['id']) : null;
        }
    }

    /**
     * Removes a reservation of reserve() again (the file could not be read or no followup could be
     * added: a later attempt may try again). Rows of files read before are kept.
     */
    public static function release(int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($id > 0 && $DB->tableExists(self::TABLE)) {
            $DB->delete(self::TABLE, ['id' => $id, 'status' => self::RESERVED]);
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
            [
                'documents_id'     => $documents_id,
                'itilfollowups_id' => $followups_id,
                'status'           => $followups_id > 0 ? self::ADDED : self::EMPTY,
                'date_creation'    => date('Y-m-d H:i:s'),
            ],
            self::key($parent, $sha1sum),
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
     * again (unless another followup of the plugin with its text is still there, see isDone()).
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
     * Hook "item_update" of followups and tasks: made private, the files on them may not be
     * visible any more to everybody who sees the ticket. The followups of the plugin with their
     * text are made private then as well (see DocumentHandler::mustBePrivate()).
     */
    public static function onSourceUpdate(CommonDBTM $item): void
    {
        if (!in_array('is_private', $item->updates, true) || Cast::int($item->fields['is_private'] ?? 0) !== 1) {
            return;
        }
        $parent = DocumentHandler::parentOf($item::class, $item->getID());
        if ($parent !== null) {
            self::hideTexts($parent);
        }
    }

    /**
     * Hook "item_purge" of document links: a file taken off the ticket (or off one of its
     * followups or tasks) may not be visible any more; its text is made private then.
     */
    public static function onDocumentUnlink(CommonDBTM $link): void
    {
        $parent = DocumentHandler::parentOf(Cast::string($link->fields['itemtype'] ?? ''), Cast::int($link->fields['items_id'] ?? 0));
        if ($parent !== null) {
            self::hideTexts($parent);
        }
    }

    /**
     * Makes the public followups of the plugin of an ITIL object private whose file must not be
     * public any more.
     */
    private static function hideTexts(CommonITILObject $parent): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists(self::TABLE)) {
            return;
        }
        $rows = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['itemtype' => $parent::class, 'items_id' => $parent->getID(), 'status' => self::ADDED],
        ]);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $followup = new ITILFollowup();
            $document = new Document();
            if (!$followup->getFromDB(Cast::int($row['itilfollowups_id'])) || Cast::int($followup->fields['is_private']) === 1) {
                continue;
            }
            // Document gone: its text is private from now on as well
            if (!$document->getFromDB(Cast::int($row['documents_id'])) || DocumentHandler::mustBePrivate($parent, $document)) {
                try {
                    $followup->update(['id' => $followup->getID(), 'is_private' => 1, '_disablenotif' => true, '_no_reopen' => true]);
                } catch (Throwable $e) {
                    Toolbox::logInFile('pdf2text', sprintf("Followup %d: could not be made private: %s\n", $followup->getID(), $e->getMessage()));
                }
            }
        }
    }

    /**
     * Unique key of a file for an ITIL object.
     *
     * @return array{itemtype: string, items_id: int, sha1sum: string}
     */
    private static function key(CommonITILObject $parent, string $sha1sum): array
    {
        return ['itemtype' => $parent::class, 'items_id' => $parent->getID(), 'sha1sum' => $sha1sum];
    }

    /**
     * Whether a row is a reservation left over by a process that died while reading.
     *
     * @param array<mixed> $row
     */
    private static function isStale(array $row): bool
    {
        if (($row['status'] ?? '') !== self::RESERVED) {
            return false;
        }
        try {
            return strtotime(Cast::string($row['date_creation'] ?? '')) < time() - self::STALE_AFTER;
        } catch (Throwable) {
            // No readable date: taken as left over
            return true;
        }
    }

    /**
     * Number of rows changed by the last query: getAffectedRows() since GLPI 12 (affectedRows()
     * is deprecated there), affectedRows() in GLPI 11 – called by name, so that both work.
     */
    private static function affectedRows(): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $method = method_exists($DB, 'getAffectedRows') ? 'getAffectedRows' : 'affectedRows';

        return Cast::int($DB->$method());
    }

    /**
     * Whether a followup of the plugin with the text of the file is on the ITIL object: its
     * heading carries the checksum (data-pdf2text="…", see DocumentHandler::buildContent()).
     */
    private static function hasMarkedFollowup(CommonITILObject $parent, string $sha1sum): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => [
                'itemtype' => $parent::class,
                'items_id' => $parent->getID(),
                // The checksum is hexadecimal (no LIKE wildcards in it)
                'content'  => ['LIKE', '%data-pdf2text="' . $sha1sum . '"%'],
            ],
            'LIMIT' => 1,
        ]);

        return count($rows) > 0;
    }

    /**
     * Whether a followup written before the tracking started already holds the text of a file
     * with this name: followups of versions before the checksum marker start with a heading
     * "<p><strong>…" naming the file in quotes, in English or German (1.0.0) or in one of the
     * later languages (French « », Polish „ ”, Russian « », and " elsewhere). Newer followups are
     * not looked at: for them the table and the marker know, and a new file with an old name must
     * not count as read.
     */
    private static function hasOldFollowup(CommonITILObject $parent, string $filename): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $since = Config::getConfigurationValue(Settings::CONTEXT, self::SINCE_KEY);
        if ($filename === '' || !is_string($since) || $since === '') {
            return false;
        }

        // The name as written in the heading (HTML-escaped), with the LIKE wildcards % and _ (and
        // the escape character \) escaped; in quotes, so that "a.pdf" does not match "data.pdf"
        $name   = addcslashes(htmlescape($filename), '%_\\');
        $nbsp   = "\u{00A0}";
        $quotes = [['&quot;', '&quot;'], ['„', '“'], ['„', '”'], ['«' . $nbsp, $nbsp . '»'], ['«', '»'], ['« ', ' »']];

        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => [
                'itemtype'      => $parent::class,
                'items_id'      => $parent->getID(),
                'date_creation' => ['<', $since],
                ['content' => ['LIKE', '<p><strong>%']],
                ['OR' => array_map(static fn(array $q) => ['content' => ['LIKE', '%' . $q[0] . $name . $q[1] . '%']], $quotes)],
            ],
            'LIMIT' => 1,
        ]);

        return count($rows) > 0;
    }
}
