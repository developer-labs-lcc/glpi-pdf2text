<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.1.0
 * @changed   2026-11-15
 * @created   2026-11-15
 */

namespace GlpiPlugin\Pdf2text;

use CommonDBTM;
use CommonITILObject;
use Config;
use Html;
use MassiveAction;
use Session;

/**
 * Massive action "Copy the text of the attachments (PDF2Text)" in the lists of tickets,
 * changes and problems: does for the chosen objects what the console command
 * plugins:pdf2text:process does (files read before are skipped).
 *
 * Offered to users who may change the GLPI configuration (like the settings of the plugin),
 * for the types switched on in the settings. Registered in setup.php (hook "use_massive_action")
 * and hook.php (plugin_pdf2text_MassiveActions()).
 */
final class MassiveActions
{
    public const ACTION = 'process';

    /** Keys of the plugin in MassiveAction::$results (kept by GLPI across the reloads of a run) */
    private const RESULTS_KEY = 'pdf2text_totals';
    private const MESSAGE_KEY = 'pdf2text_message';

    /**
     * Actions for the list of the given type (key = class + ":" + action, value = label).
     *
     * @return array<string, string>
     */
    public static function forItemtype(string $itemtype): array
    {
        if (!Settings::isEnabledFor($itemtype) || !Session::haveRight(Config::$rightname, UPDATE)) {
            return [];
        }

        return [
            self::class . MassiveAction::CLASS_ACTION_SEPARATOR . self::ACTION
                => '<i class="ti ti-file-text"></i>' . htmlescape(__('Copy the text of the attachments (PDF2Text)', 'pdf2text')),
        ];
    }

    /**
     * Second step of the massive action: only a short note and the button.
     */
    public static function showMassiveActionsSubForm(MassiveAction $ma): bool
    {
        if ($ma->getAction() !== self::ACTION) {
            return false;
        }

        echo '<p>' . htmlescape(__('The text of PDF, Word and OpenDocument attachments that were not read yet is added as a followup.', 'pdf2text')) . '</p>';
        echo Html::submit(_x('button', 'Post'), ['name' => 'massiveaction']);

        return true;
    }

    /**
     * @param array<int|string, mixed> $ids
     */
    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids): void
    {
        // Checked again here: the request could be sent without the list offering the action
        if ($ma->getAction() !== self::ACTION
            || !$item instanceof CommonITILObject
            || !Settings::isEnabledFor($item::class)
            || !Session::haveRight(Config::$rightname, UPDATE)) {
            foreach ($ids as $id) {
                $ma->itemDone($item::class, Cast::int($id), MassiveAction::ACTION_NORIGHT);
            }
            return;
        }

        // Totals of the whole run in $ma->results: GLPI keeps them in the session when it reloads
        // the page during a long run (MassiveAction::itemDone() redirects after 5 seconds, also in
        // the middle of this loop), and continues with the items left
        $stored = $ma->results[self::RESULTS_KEY] ?? [];
        $totals = [];
        foreach ([DocumentHandler::ADDED, DocumentHandler::EMPTY, DocumentHandler::DONE, DocumentHandler::FAILED] as $key) {
            $totals[$key] = is_array($stored) ? Cast::int($stored[$key] ?? 0) : 0;
        }
        foreach ($ids as $id) {
            $result = MassiveAction::ACTION_KO;
            if ($item->getFromDB(Cast::int($id))) {
                $counts = DocumentHandler::processItem($item);
                foreach ([DocumentHandler::ADDED, DocumentHandler::EMPTY, DocumentHandler::DONE, DocumentHandler::FAILED] as $key) {
                    $totals[$key] += $counts[$key];
                }
                $result = $counts[DocumentHandler::FAILED] > 0 ? MassiveAction::ACTION_KO : MassiveAction::ACTION_OK;
            }
            // Before itemDone(), which may reload the page
            $ma->results[self::RESULTS_KEY] = $totals;
            self::showSummary($ma, $totals);
            $ma->itemDone($item::class, Cast::int($id), $result);
        }
    }

    /**
     * Shows the summary of the run after it (one message, replaced after every item, so that it
     * is right however often GLPI reloads the page). Not with $ma->addMessage(): GLPI shows those
     * messages as errors (front/massiveaction.php), also a summary of a successful run.
     *
     * @param array<string, int> $totals
     */
    private static function showSummary(MassiveAction $ma, array $totals): void
    {
        $type = $totals[DocumentHandler::FAILED] > 0 ? WARNING : INFO;
        $text = htmlescape(self::summary($totals));

        // Remove the message of the previous item (same run), then add the new one
        $previous = $ma->results[self::MESSAGE_KEY] ?? null;
        if (is_array($previous)) {
            $previous_type = Cast::int($previous['type'] ?? 0);
            $previous_text = Cast::string($previous['text'] ?? '');
            $all           = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
            if (is_array($all) && is_array($all[$previous_type] ?? null)) {
                $all[$previous_type] = array_values(array_filter($all[$previous_type], static fn($message) => $message !== $previous_text));
                $_SESSION['MESSAGE_AFTER_REDIRECT'] = $all;
            }
        }
        Session::addMessageAfterRedirect($text, false, $type);
        $ma->results[self::MESSAGE_KEY] = ['type' => $type, 'text' => $text];
    }

    /**
     * E.g. "PDF2Text: 2 followups added, 1 file without text". Only the numbers above 0.
     *
     * @param array<string, int> $totals
     */
    public static function summary(array $totals): string
    {
        $parts = [];
        if ($totals[DocumentHandler::ADDED] > 0) {
            $parts[] = sprintf(_n('%d followup added', '%d followups added', $totals[DocumentHandler::ADDED], 'pdf2text'), $totals[DocumentHandler::ADDED]);
        }
        if ($totals[DocumentHandler::DONE] > 0) {
            $parts[] = sprintf(_n('%d file was copied before', '%d files were copied before', $totals[DocumentHandler::DONE], 'pdf2text'), $totals[DocumentHandler::DONE]);
        }
        if ($totals[DocumentHandler::EMPTY] > 0) {
            $parts[] = sprintf(_n('%d file without text', '%d files without text', $totals[DocumentHandler::EMPTY], 'pdf2text'), $totals[DocumentHandler::EMPTY]);
        }
        if ($totals[DocumentHandler::FAILED] > 0) {
            $parts[] = sprintf(_n('%d file could not be read (see files/_log/pdf2text.log)', '%d files could not be read (see files/_log/pdf2text.log)', $totals[DocumentHandler::FAILED], 'pdf2text'), $totals[DocumentHandler::FAILED]);
        }

        return 'PDF2Text: ' . ($parts === [] ? __('no attachments with text', 'pdf2text') : implode(', ', $parts));
    }
}
