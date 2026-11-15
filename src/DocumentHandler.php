<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.4.0
 * @created   2026-10-22
 * @changed   2026-11-15
 */

namespace GlpiPlugin\Pdf2text;

use CommonITILObject;
use CommonITILTask;
use CommonITILValidation;
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
 * - reply mail or followup / task / solution / approval with a file: the file is linked to that
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
     * itself and to its followups, tasks, solutions and approvals, in the order they were
     * attached (all of them, whatever the rights of the user: used by the console command and
     * by the massive action for administrators).
     *
     * @param bool $dry_run only count the files that would be read (result PENDING)
     *
     * @return array<string, int> number of files per result (ADDED, EMPTY, DONE, PENDING, FAILED)
     */
    public static function processItem(CommonITILObject $parent, bool $dry_run = false): array
    {
        $counts = [self::ADDED => 0, self::EMPTY => 0, self::DONE => 0, self::PENDING => 0, self::FAILED => 0];
        if (!Settings::isEnabledFor($parent::class)) {
            return $counts;
        }

        foreach (self::linkedDocumentIds($parent) as $documents_id) {
            $document = new Document();
            if (!$document->getFromDB($documents_id)) {
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
     * Reads one file of an ITIL object on request (button at the attachment in the timeline, see
     * AttachmentButton), also if its text was added before when $force is true.
     *
     * @return string one of the result constants; DONE: read before and $force is false
     *
     * @throws RuntimeException if the file is not one the user may see on the object, or cannot be read
     */
    public static function processOne(CommonITILObject $parent, Document $document, bool $force): string
    {
        // Only files of this object that the user may see (e.g. not those of private tasks without
        // the right to see them): their text would end up in a followup of this user
        if (!self::isAttached($parent, $document, true)) {
            throw new RuntimeException(sprintf('Document %d is not attached to %s #%d.', $document->getID(), $parent::class, $parent->getID()));
        }

        return self::processDocument($parent, $document, false, $force);
    }

    /**
     * Whether the document is attached to the ITIL object or to one of its followups, tasks,
     * solutions or approvals; with $check_rights only those the user of the session may see.
     */
    public static function isAttached(CommonITILObject $parent, Document $document, bool $check_rights = false): bool
    {
        return in_array($document->getID(), self::linkedDocumentIds($parent, $check_rights), true);
    }

    /**
     * IDs of the documents of the file types switched on that the user of the session may see on
     * the ITIL object (for the buttons at the attachments, see AttachmentButton).
     *
     * @return list<int>
     */
    public static function readableDocumentIds(CommonITILObject $parent): array
    {
        $ids = [];
        foreach (self::linkedDocumentIds($parent, true) as $documents_id) {
            $document = new Document();
            if ($document->getFromDB($documents_id)
                && Settings::formatOf(Cast::string($document->fields['filename']), Cast::string($document->fields['mime'])) !== null) {
                $ids[] = $documents_id;
            }
        }

        return $ids;
    }

    /**
     * IDs of the documents attached to the ITIL object itself and to its followups, tasks,
     * solutions and approvals, in the order they were attached. The query is GLPI's own (the one
     * of the documents tab and the timeline): with $check_rights it leaves out what the user of the
     * session may not see, e.g. files of private followups and tasks.
     *
     * @return list<int>
     */
    private static function linkedDocumentIds(CommonITILObject $parent, bool $check_rights = false): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => [Document_Item::getTableField('documents_id')],
            'FROM'   => Document_Item::getTable(),
            'WHERE'  => $parent->getAssociatedDocumentsCriteria(!$check_rights),
            'ORDER'  => Document_Item::getTableField('id'),
        ]) as $link) {
            if (is_array($link)) {
                $ids[] = Cast::int($link['documents_id'] ?? 0);
            }
        }

        // A document linked twice (e.g. to a followup and to the ticket) is read once
        return array_values(array_unique($ids));
    }

    /**
     * Whether the document is attached to the ITIL object only through private followups or tasks
     * (or approvals): its text then goes into a private followup, whatever the setting says, so
     * that a requester does not read the text of a file only technicians could see.
     */
    private static function onlyPrivate(CommonITILObject $parent, Document $document): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $links = $DB->request([
            'SELECT' => [Document_Item::getTableField('itemtype'), Document_Item::getTableField('items_id')],
            'FROM'   => Document_Item::getTable(),
            'WHERE'  => [Document_Item::getTableField('documents_id') => $document->getID()] + [$parent->getAssociatedDocumentsCriteria(true)],
        ]);
        $found = false;
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $found = true;
            $item  = getItemForItemtype(Cast::string($link['itemtype'] ?? ''));
            if ($item instanceof ITILFollowup || $item instanceof CommonITILTask) {
                if ($item->getFromDB(Cast::int($link['items_id'] ?? 0)) && Cast::int($item->fields['is_private'] ?? 1) === 0) {
                    return false;
                }
                continue;
            }
            if ($item instanceof CommonITILValidation) {
                continue;
            }
            // The ITIL object itself or a solution: visible to everybody who sees the object
            return false;
        }

        return $found;
    }

    /**
     * Reads one file and adds its text as a followup to the ITIL object, unless it was read
     * for that object before (with $force: also then, e.g. on request by the button).
     *
     * @return string one of the result constants (ADDED, EMPTY, DONE, PENDING, IGNORED)
     *
     * @throws RuntimeException if the file cannot be read
     */
    private static function processDocument(CommonITILObject $parent, Document $document, bool $dry_run = false, bool $force = false): string
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
        if (!$force && (isset(self::$done[$key]) || ProcessedFiles::isDone($parent, $sha1sum, $filename))) {
            return self::DONE;
        }
        if ($dry_run) {
            return self::PENDING;
        }
        // Reserved before reading, against a second process reading the same file now (null: a row
        // exists already; with $force the text is added again all the same)
        $reservation = ProcessedFiles::reserve($parent, $sha1sum, $document->getID());
        if ($reservation === null && !$force) {
            return self::DONE;
        }
        self::$done[$key] = true;
        try {
            return self::readAndAdd($parent, $document, $format, $path, $sha1sum);
        } catch (Throwable $e) {
            // Not remembered: the next attempt (e.g. the console command) tries again
            unset(self::$done[$key]);
            ProcessedFiles::release((int) $reservation);
            throw $e;
        }
    }

    /**
     * Reads the file and adds its text as a followup (part of processDocument()).
     *
     * @return string ADDED or EMPTY
     *
     * @throws RuntimeException if the file cannot be read or the followup cannot be added
     */
    private static function readAndAdd(CommonITILObject $parent, Document $document, string $format, string $path, string $sha1sum): string
    {
        $filename  = Cast::string($document->fields['filename']);
        $max_chars = Settings::getInt('max_chars', 1000, 1000000);
        if ($format === 'pdf') {
            $text = (new PdfTextExtractor(Cast::string(Settings::get('pdftotext_path'))))->extract($path);
            // PDF: the text in a <pre> block, so that the columns of pdftotext stay aligned
            [$text, $shortened] = PdfTextExtractor::truncate($text, $max_chars);
            $body = '<pre>' . htmlescape($text) . '</pre>';
        } else {
            // Word, OpenDocument: formatted (headings, paragraphs, lists, tables), lines wrap
            $blocks = (new OfficeTextExtractor())->extractBlocks($path, $format);
            $text   = OfficeTextExtractor::toText($blocks);
            [$body, $shortened] = OfficeTextExtractor::toHtml($blocks, $max_chars);
        }
        if ($text === '') {
            // Scanned PDF without text layer, document with images only: nothing to copy.
            Toolbox::logInFile(self::LOG, sprintf(
                $format === 'pdf' ? "Document %d: no text layer (scanned PDF?)\n" : "Document %d: no text\n",
                $document->getID(),
            ));
            ProcessedFiles::remember($parent, $sha1sum, $document->getID(), 0);
            return self::EMPTY;
        }

        $followup = new ITILFollowup();
        $followup_id = $followup->add([
            'itemtype'      => $parent::class,
            'items_id'      => $parent->getID(),
            'content'       => self::buildContent($filename, $format, $body, $shortened),
            // Private as set, and always if the file is only on private followups or tasks
            'is_private'    => (bool) Settings::get('is_private') || self::onlyPrivate($parent, $document) ? 1 : 0,
            // No mail for this followup: the notification for the ticket or followup itself is enough.
            '_disablenotif' => true,
            // Do not reopen a solved or closed ticket because of the added text.
            '_no_reopen'    => true,
        ]);
        if ($followup_id === false) {
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

        // Tasks and approvals store their parent in a foreign key, e.g. TicketTask::tickets_id.
        if ($item instanceof CommonITILTask || $item instanceof CommonITILValidation) {
            $parent_type = $item::getItilObjectItemType();
            $foreign_key = getForeignKeyFieldForItemType($parent_type);

            return self::getParent($parent_type, Cast::int($item->fields[$foreign_key] ?? 0));
        }

        return null;
    }

    /**
     * HTML of the followup: a heading with the file name, then the text ($body: a <pre> block for
     * PDFs, formatted HTML for Word and OpenDocument). ProcessedFiles recognises followups of
     * earlier versions by the file name in the heading and their <pre> block.
     */
    private static function buildContent(string $filename, string $format, string $body, bool $shortened): string
    {
        $heading = $format === 'pdf'
            ? sprintf(__('Text of the PDF attachment "%s"', 'pdf2text'), $filename)
            : sprintf(__('Text of the attachment "%s"', 'pdf2text'), $filename);
        $html = '<p><strong>' . htmlescape($heading) . '</strong></p>' . $body;
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
