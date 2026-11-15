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

namespace GlpiPlugin\Pdf2text;

use CommonITILObject;
use CommonITILTask;
use DBmysql;
use Document;
use Document_Item;
use ITILFollowup;
use ITILSolution;
use RuntimeException;
use Throwable;
use Toolbox;

use function Safe\sha1_file;

/**
 * Adds the text of an attachment (PDF, DOCX, ODT) as a followup to its ticket, change or problem.
 *
 * Called by GLPI for every new document link (hook "item_add" on Document_Item):
 * - new ticket from the mail collector or the form: the file is linked to the ticket itself;
 * - reply mail or followup / task / solution with a file: the file is linked to that
 *   sub-item, and the text goes to its parent ticket, change or problem.
 *
 * processItem() does the same afterwards for all files of an ITIL object (files attached before
 * the plugin was enabled; used by the console command and the massive action).
 *
 * A file is read only once per ITIL object (see ProcessedFiles): attached again, e.g. by a reply
 * mail with the same PDF, it adds no second followup.
 */
final class DocumentHandler
{
    /** Name of the log file in files/_log (without ".log"). */
    private const LOG = 'pdf2text';

    /** Results of processDocument() */
    public const ADDED   = 'added';    // followup with the text added
    public const EMPTY   = 'empty';    // read, but no text in it (e.g. a scanned PDF)
    public const DONE    = 'done';     // read before for this ITIL object
    public const PENDING = 'pending';  // dry run: would be read
    public const FAILED  = 'failed';   // could not be read (see the log)
    public const IGNORED = 'ignored';  // no file type the plugin reads (or switched off)

    /**
     * Files handled for an ITIL object in this request ("checksum:type:id"), so that a file
     * linked twice in one go (e.g. to a followup and to its ticket) gives only one followup,
     * even before the first one is stored.
     *
     * @var array<string, true>
     */
    private static array $done = [];

    public static function onDocumentItemAdd(Document_Item $document_item): void
    {
        try {
            self::handle($document_item);
        } catch (Throwable $e) {
            // Never break the creation of the ticket or followup because of an unreadable file.
            Toolbox::logInFile(self::LOG, sprintf(
                "Document %d: %s\n",
                Cast::int($document_item->fields['documents_id'] ?? 0),
                $e->getMessage(),
            ));
        }
    }

    private static function handle(Document_Item $document_item): void
    {
        $parent = self::getParent(Cast::string($document_item->fields['itemtype']), Cast::int($document_item->fields['items_id']));
        if ($parent === null || !Settings::isEnabledFor($parent::class)) {
            return;
        }

        $document = new Document();
        if (!$document->getFromDB(Cast::int($document_item->fields['documents_id']))) {
            return;
        }

        self::processDocument($parent, $document);
    }

    /**
     * Reads all files of an ITIL object that were not read yet: those attached to the object
     * itself and to its followups, tasks and solutions, in the order they were attached.
     *
     * @param bool $dry_run only count the files that would be read (result PENDING)
     *
     * @return array<string, int> number of files per result (ADDED, EMPTY, DONE, PENDING, FAILED)
     */
    public static function processItem(CommonITILObject $parent, bool $dry_run = false): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $counts = [self::ADDED => 0, self::EMPTY => 0, self::DONE => 0, self::PENDING => 0, self::FAILED => 0];
        if (!Settings::isEnabledFor($parent::class)) {
            return $counts;
        }

        // The object itself and its sub-items that can carry files
        $where = [['itemtype' => $parent::class, 'items_id' => $parent->getID()]];
        $subitems = [
            ITILFollowup::class => ['itemtype' => $parent::class, 'items_id' => $parent->getID()],
            ITILSolution::class => ['itemtype' => $parent::class, 'items_id' => $parent->getID()],
        ];
        $task_class = $parent::getTaskClass();
        if ($task_class !== null && is_a($task_class, CommonITILTask::class, true)) {
            // Tasks name their parent in a foreign key, e.g. TicketTask::tickets_id
            $subitems[$task_class] = [getForeignKeyFieldForItemType($parent::class) => $parent->getID()];
        }
        foreach ($subitems as $itemtype => $criteria) {
            $ids = array_column(iterator_to_array($DB->request([
                'SELECT' => ['id'],
                'FROM'   => getTableForItemType($itemtype),
                'WHERE'  => $criteria,
            ]), false), 'id');
            if ($ids !== []) {
                $where[] = ['itemtype' => $itemtype, 'items_id' => $ids];
            }
        }

        $links = $DB->request([
            'SELECT' => ['documents_id'],
            'FROM'   => Document_Item::getTable(),
            'WHERE'  => ['OR' => $where],
            'ORDER'  => 'id',
        ]);
        foreach ($links as $link) {
            $document = new Document();
            if (!is_array($link) || !$document->getFromDB(Cast::int($link['documents_id'] ?? 0))) {
                continue;
            }
            try {
                $result = self::processDocument($parent, $document, $dry_run);
            } catch (Throwable $e) {
                Toolbox::logInFile(self::LOG, sprintf("Document %d: %s\n", $document->getID(), $e->getMessage()));
                $result = self::FAILED;
            }
            if ($result !== self::IGNORED) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * Reads one file and adds its text as a followup to the ITIL object, unless it was read
     * for that object before.
     *
     * @return string one of the result constants (ADDED, EMPTY, DONE, PENDING, IGNORED)
     *
     * @throws RuntimeException if the file cannot be read
     */
    private static function processDocument(CommonITILObject $parent, Document $document, bool $dry_run = false): string
    {
        $filename = Cast::string($document->fields['filename']);
        $format   = Settings::formatOf($filename, Cast::string($document->fields['mime']));
        if ($format === null) {
            return self::IGNORED;
        }

        $path = GLPI_DOC_DIR . '/' . Cast::string($document->fields['filepath']);
        // GLPI stores the checksum of every file; computed here only for documents without one
        $sha1sum = Cast::string($document->fields['sha1sum']);
        if ($sha1sum === '') {
            $sha1sum = is_readable($path) ? sha1_file($path) : 'document:' . $document->getID();
        }

        $key = $sha1sum . ':' . $parent::class . ':' . $parent->getID();
        if (isset(self::$done[$key]) || ProcessedFiles::isDone($parent, $sha1sum, $filename)) {
            return self::DONE;
        }
        if ($dry_run) {
            return self::PENDING;
        }
        self::$done[$key] = true;

        $text = $format === 'pdf'
            ? (new PdfTextExtractor(Cast::string(Settings::get('pdftotext_path'))))->extract($path)
            : (new OfficeTextExtractor())->extract($path, $format);
        if ($text === '') {
            // Scanned PDF without text layer, document with images only: nothing to copy.
            Toolbox::logInFile(self::LOG, sprintf(
                $format === 'pdf' ? "Document %d: no text layer (scanned PDF?)\n" : "Document %d: no text\n",
                $document->getID(),
            ));
            ProcessedFiles::remember($parent, $sha1sum, $document->getID(), 0);
            return self::EMPTY;
        }

        [$text, $shortened] = PdfTextExtractor::truncate($text, Settings::getInt('max_chars', 1000, 1000000));

        $followup = new ITILFollowup();
        $followup_id = $followup->add([
            'itemtype'      => $parent::class,
            'items_id'      => $parent->getID(),
            'content'       => self::buildContent($filename, $format, $text, $shortened),
            'is_private'    => Cast::int(Settings::get('is_private')),
            // No mail for this followup: the notification for the ticket or followup itself is enough.
            '_disablenotif' => true,
            // Do not reopen a solved or closed ticket because of the added text.
            '_no_reopen'    => true,
        ]);
        if ($followup_id === false) {
            // Not remembered: the next attempt (e.g. the console command) tries again
            unset(self::$done[$key]);
            throw new RuntimeException(sprintf('The followup for %s #%d could not be added.', $parent::class, $parent->getID()));
        }
        ProcessedFiles::remember($parent, $sha1sum, $document->getID(), Cast::int($followup_id));

        return self::ADDED;
    }

    /**
     * Returns the ticket, change or problem the document belongs to, or null for other items.
     */
    private static function getParent(string $itemtype, int $items_id): ?CommonITILObject
    {
        $item = getItemForItemtype($itemtype);
        if ($item === false || !$item->getFromDB($items_id)) {
            return null;
        }

        if ($item instanceof CommonITILObject) {
            return $item;
        }

        // Followups and solutions store their parent as itemtype + items_id.
        if ($item instanceof ITILFollowup || $item instanceof ITILSolution) {
            return self::getParent(Cast::string($item->fields['itemtype']), Cast::int($item->fields['items_id']));
        }

        // Tasks store their parent in a foreign key, e.g. TicketTask::tickets_id.
        if ($item instanceof CommonITILTask) {
            $parent_type = $item::getItilObjectItemType();
            $foreign_key = getForeignKeyFieldForItemType($parent_type);

            return self::getParent($parent_type, Cast::int($item->fields[$foreign_key] ?? 0));
        }

        return null;
    }

    /**
     * HTML of the followup: a heading with the file name and the text in a <pre> block,
     * so that line breaks and the column layout of pdftotext are kept. ProcessedFiles also
     * recognises followups of earlier versions by these two parts.
     */
    private static function buildContent(string $filename, string $format, string $text, bool $shortened): string
    {
        $heading = $format === 'pdf'
            ? sprintf(__('Text of the PDF attachment "%s"', 'pdf2text'), $filename)
            : sprintf(__('Text of the attachment "%s"', 'pdf2text'), $filename);
        $html = '<p><strong>' . htmlescape($heading) . '</strong></p>'
            . '<pre>' . htmlescape($text) . '</pre>';
        if ($shortened) {
            $html .= '<p><em>' . htmlescape(__('The text was shortened. The complete text is in the attachment.', 'pdf2text')) . '</em></p>';
        }

        return $html;
    }

    /**
     * Forget the handled documents (for tests and long running processes).
     */
    public static function reset(): void
    {
        self::$done = [];
    }
}
