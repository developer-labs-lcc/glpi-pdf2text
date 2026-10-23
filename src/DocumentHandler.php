<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 */

namespace GlpiPlugin\Pdftext;

use CommonITILObject;
use CommonITILTask;
use Document;
use Document_Item;
use ITILFollowup;
use ITILSolution;
use Throwable;
use Toolbox;

/**
 * Adds the text of a PDF attachment as a followup to its ticket, change or problem.
 *
 * Called by GLPI for every new document link (hook "item_add" on Document_Item):
 * - new ticket from the mail collector or the form: the PDF is linked to the ticket itself;
 * - reply mail or followup / task / solution with a file: the PDF is linked to that
 *   sub-item, and the text goes to its parent ticket, change or problem.
 */
final class DocumentHandler
{
    /** Name of the log file in files/_log (without ".log"). */
    private const LOG = 'pdftext';

    /**
     * Document/item pairs handled in this request, so that a PDF linked twice to the same
     * parent (e.g. to a followup and to its ticket) gives only one followup.
     *
     * @var array<string, true>
     */
    private static array $done = [];

    public static function onDocumentItemAdd(Document_Item $document_item): void
    {
        try {
            self::handle($document_item);
        } catch (Throwable $e) {
            // Never break the creation of the ticket or followup because of an unreadable PDF.
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
        if (!$document->getFromDB(Cast::int($document_item->fields['documents_id'])) || !self::isPdf($document)) {
            return;
        }

        $key = $document->getID() . ':' . $parent::class . ':' . $parent->getID();
        if (isset(self::$done[$key])) {
            return;
        }
        self::$done[$key] = true;

        $extractor = new PdfTextExtractor(Cast::string(Settings::get('pdftotext_path')));
        $text      = $extractor->extract(GLPI_DOC_DIR . '/' . Cast::string($document->fields['filepath']));
        if ($text === '') {
            // Scanned PDF without text layer: nothing to copy.
            Toolbox::logInFile(self::LOG, sprintf("Document %d: no text layer (scanned PDF?)\n", $document->getID()));
            return;
        }

        [$text, $shortened] = PdfTextExtractor::truncate($text, Settings::getInt('max_chars', 1000, 1000000));

        $followup = new ITILFollowup();
        $followup->add([
            'itemtype'      => $parent::class,
            'items_id'      => $parent->getID(),
            'content'       => self::buildContent(Cast::string($document->fields['filename']), $text, $shortened),
            'is_private'    => Cast::int(Settings::get('is_private')),
            // No mail for this followup: the notification for the ticket or followup itself is enough.
            '_disablenotif' => true,
            // Do not reopen a solved or closed ticket because of the added text.
            '_no_reopen'    => true,
        ]);
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

    private static function isPdf(Document $document): bool
    {
        return $document->fields['mime'] === 'application/pdf'
            || strtolower(pathinfo(Cast::string($document->fields['filename']), PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * HTML of the followup: a heading with the file name and the text in a <pre> block,
     * so that line breaks and the column layout of pdftotext are kept.
     */
    private static function buildContent(string $filename, string $text, bool $shortened): string
    {
        $html = '<p><strong>' . htmlescape(sprintf(__('Text of the PDF attachment "%s"', 'pdftext'), $filename)) . '</strong></p>'
            . '<pre>' . htmlescape($text) . '</pre>';
        if ($shortened) {
            $html .= '<p><em>' . htmlescape(__('The text was shortened. The complete text is in the attachment.', 'pdftext')) . '</em></p>';
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
