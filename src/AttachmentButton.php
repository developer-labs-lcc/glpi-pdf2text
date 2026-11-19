<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.2.0
 * @created   2026-11-15
 * @changed   2026-11-19
 */

namespace GlpiPlugin\Pdf2text;

use Change;
use CommonITILObject;
use ITILFollowup;
use Problem;
use Session;
use Ticket;

use function Safe\json_encode;
use function Safe\parse_url;
use function Safe\preg_match;

/**
 * Button "Copy the text into a followup" next to the buttons GLPI shows at an attachment in the
 * timeline of a ticket, change or problem (edit, delete, import exclusion list).
 *
 * GLPI has no hook for these buttons, so public/js/attachments.js adds it to the page. It gets
 * what it needs from a <meta name="pdf2text-attachments"> tag in the header of the page
 * (headerTag()): the address to call, the ITIL object of the page, the documents that get the
 * button and the texts. The tag is only there for users who may add a followup to that object,
 * and lists only the documents of the file types switched on that they may see on it.
 * A click is handled by Controller\AttachmentController.
 */
final class AttachmentButton
{
    /** Name of the meta tag read by attachments.js */
    public const META = 'pdf2text-attachments';

    /** Address of the controller, below the root of GLPI */
    public const URL = '/plugins/pdf2text/attachment';

    /** Form pages of the ITIL objects: script name => class */
    private const PAGES = [
        'ticket'  => Ticket::class,
        'change'  => Change::class,
        'problem' => Problem::class,
    ];

    /**
     * The ITIL object shown by the requested page, if it is the form of a ticket, change or
     * problem (e.g. /glpi/front/ticket.form.php?id=12), otherwise null.
     */
    public static function pageItem(string $request_uri): ?CommonITILObject
    {
        $path = Cast::string(parse_url($request_uri, PHP_URL_PATH));
        // "/front/" + one of the names + ".form.php" at the end of the path, e.g. ".../front/ticket.form.php"
        if (preg_match('#/front/(ticket|change|problem)\.form\.php$#', $path, $match) !== 1) {
            return null;
        }
        $name = $match[1] ?? '';
        if (!isset(self::PAGES[$name])) {
            return null;
        }
        $itemtype = self::PAGES[$name];
        $query = [];
        parse_str(Cast::string(parse_url($request_uri, PHP_URL_QUERY)), $query);
        $id = Cast::int($query['id'] ?? 0);
        if ($id <= 0 || !Settings::isEnabledFor($itemtype)) {
            return null;
        }

        $item = new $itemtype();

        return $item->getFromDB($id) ? $item : null;
    }

    /**
     * Whether the user of the session may add a followup to the ITIL object: the same check as
     * GLPI's "Answer" button (rights of the profile, the object is visible), and not on closed
     * objects.
     */
    public static function mayUse(CommonITILObject $item): bool
    {
        // Not on closed objects: GLPI allows a followup there only to reopen them, and the plugin
        // does not reopen (its followup is no answer)
        if (!ITILFollowup::canCreate() || in_array($item->fields['status'] ?? null, $item::getClosedStatusArray(), false)) {
            return false;
        }
        $followup = new ITILFollowup();
        $followup->fields = ['itemtype' => $item::class, 'items_id' => $item->getID()];

        return $followup->canCreateItem();
    }

    /**
     * Whether the user may add the text of a file a second time ("Add again"): only with the
     * right to update all followups, so that the button cannot be used to fill a ticket with
     * copies.
     */
    public static function mayForce(): bool
    {
        return (bool) Session::haveRight(ITILFollowup::$rightname, ITILFollowup::UPDATEALL);
    }

    /**
     * Meta tag for the hook "add_header_tag", or null if the page gets no button (no form of an
     * ITIL object, type switched off, no right to add a followup).
     *
     * @return array{tag: string, properties: array<string, string>}|null
     */
    public static function headerTag(string $request_uri, string $root_doc): ?array
    {
        $item = self::pageItem($request_uri);
        if ($item === null || !self::mayUse($item)) {
            return null;
        }
        // Documents of the file types switched on that the user may see on this object (not e.g.
        // those of private tasks without the right to see them, nor of an entity note)
        $documents = DocumentHandler::readableDocumentIds($item);
        if ($documents === []) {
            return null;
        }

        return ['tag' => 'meta', 'properties' => [
            'name'    => self::META,
            'content' => json_encode([
                'url'        => $root_doc . self::URL,
                'itemtype'   => $item::class,
                'items_id'   => $item->getID(),
                'documents'  => $documents,
                'texts'      => [
                    'button'  => __('Copy the text into a followup (PDF2Text)', 'pdf2text'),
                    'title'   => 'PDF2Text',
                    'again'   => __('Add again', 'pdf2text'),
                    'error'   => __('The request failed. Please reload the page and try again.', 'pdf2text'),
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]];
    }
}
