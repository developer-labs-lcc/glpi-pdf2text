<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.3.0
 * @changed   2026-11-19
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

    /** Key of the plugin in MassiveAction::$results (kept by GLPI across the reloads of a run) */
    private const RESULTS_KEY = 'pdf2text_totals';

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
        // $ids are the items of the run not done yet (GLPI calls this again after a reload with the
        // rest), so the last one of them is the last item of the run
        $last = array_key_last($ids);
        foreach ($ids as $index => $id) {
            $result = MassiveAction::ACTION_KO;
            if (!$item->can(Cast::int($id), READ) || !AttachmentButton::mayUse($item)) {
                // Per object as well: GLPI passes the posted ids on unchecked, and the right to
                // change the configuration is not tied to entities
                $result = MassiveAction::ACTION_NORIGHT;
            } elseif ($item->getFromDB(Cast::int($id))) {
                $counts = DocumentHandler::processItem($item);
                foreach ([DocumentHandler::ADDED, DocumentHandler::EMPTY, DocumentHandler::DONE, DocumentHandler::FAILED] as $key) {
                    $totals[$key] += $counts[$key];
                }
                $result = $counts[DocumentHandler::FAILED] > 0 ? MassiveAction::ACTION_KO : MassiveAction::ACTION_OK;
            }
            // Before itemDone(), which may reload the page
            $ma->results[self::RESULTS_KEY] = $totals;
            if ($index === $last) {
                self::showSummary($totals);
            }
            $ma->itemDone($item::class, Cast::int($id), $result);
        }
    }

    /**
     * Shows the summary of the whole run once, after GLPI's own "Operation successful". Not with
     * $ma->addMessage(): GLPI shows those messages as errors (front/massiveaction.php), also a
     * summary of a successful run.
     *
     * @param array<string, int> $totals
     */
    private static function showSummary(array $totals): void
    {
        Session::addMessageAfterRedirect(
            htmlescape(self::summary($totals)),
            false,
            $totals[DocumentHandler::FAILED] > 0 ? WARNING : INFO,
        );
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
