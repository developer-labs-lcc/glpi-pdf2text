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

namespace GlpiPlugin\Pdf2text\Controller;

use Document;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Pdf2text\AttachmentButton;
use GlpiPlugin\Pdf2text\Cast;
use GlpiPlugin\Pdf2text\DocumentHandler;
use GlpiPlugin\Pdf2text\Settings;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use Toolbox;

/**
 * Click on the button "Copy the text into a followup" at an attachment (see AttachmentButton and
 * public/js/attachments.js): POST /plugins/pdf2text/attachment with itemtype, items_id,
 * documents_id and force (1 = add the text again although it was added before).
 *
 * Answer (JSON): {status, message}; status is one of the results of DocumentHandler (added,
 * done, empty, failed, ignored) or "denied". "done" asks the user whether to add the text again.
 */
final class AttachmentController extends AbstractController
{
    // For every logged-in user, also of the simplified interface (GLPI's default for plugin
    // routes is the standard interface only): the rights are checked below, per file
    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/attachment', name: 'attachment', methods: ['POST'])]
    public function attachment(Request $request): Response
    {
        $itemtype = Cast::string($request->request->get('itemtype', ''));
        // Only the types of the plugin (and switched on): the class name comes from the browser
        if (!in_array($itemtype, Settings::ITEMTYPES, true) || !Settings::isEnabledFor($itemtype)) {
            return self::answer('denied', __('PDF2Text is switched off for this type.', 'pdf2text'), Response::HTTP_FORBIDDEN);
        }
        // (a Ticket, Change or Problem, see Settings::ITEMTYPES)
        $parent = new $itemtype();
        if (!$parent->getFromDB($request->request->getInt('items_id'))
            || !AttachmentButton::mayUse($parent)) {
            return self::answer('denied', __('You may not add a followup here.', 'pdf2text'), Response::HTTP_FORBIDDEN);
        }
        $document = new Document();
        // Only a file of this object that the user may see there: the text of a file of another
        // ticket or of a private task could be confidential
        if (!$document->getFromDB($request->request->getInt('documents_id')) || !DocumentHandler::isAttached($parent, $document, true)) {
            return self::answer('failed', __('The file was not found.', 'pdf2text'), Response::HTTP_NOT_FOUND);
        }

        try {
            $result = DocumentHandler::processOne($parent, $document, $request->request->getBoolean('force'));
        } catch (Throwable $e) {
            Toolbox::logInFile('pdf2text', sprintf("Document %d (button): %s\n", $document->getID(), $e->getMessage()));
            $result = DocumentHandler::FAILED;
        }

        return match ($result) {
            // Shown by GLPI after the page is reloaded (attachments.js reloads it)
            DocumentHandler::ADDED => self::added(),
            DocumentHandler::DONE => self::answer('done', __('The text of this file was added before. Add it again?', 'pdf2text')),
            DocumentHandler::EMPTY => self::answer('empty', __('The file contains no text (e.g. a scanned PDF).', 'pdf2text')),
            DocumentHandler::IGNORED => self::answer('ignored', __('This file type is not read (see the settings of PDF2Text).', 'pdf2text')),
            default => self::answer('failed', __('The file could not be read (see files/_log/pdf2text.log).', 'pdf2text')),
        };
    }

    private static function added(): JsonResponse
    {
        $message = __('The text was added as a followup.', 'pdf2text');
        Session::addMessageAfterRedirect(htmlescape($message));

        return self::answer('added', $message);
    }

    private static function answer(string $status, string $message, int $code = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(['status' => $status, 'message' => $message], $code);
    }
}
