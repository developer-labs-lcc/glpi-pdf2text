<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.0
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

        $totals = [DocumentHandler::ADDED => 0, DocumentHandler::EMPTY => 0, DocumentHandler::FAILED => 0];
        foreach ($ids as $id) {
            if (!$item->getFromDB(Cast::int($id))) {
                $ma->itemDone($item::class, Cast::int($id), MassiveAction::ACTION_KO);
                continue;
            }
            $counts = DocumentHandler::processItem($item);
            foreach (array_keys($totals) as $result) {
                $totals[$result] += $counts[$result];
            }
            $ma->itemDone($item::class, Cast::int($id), $counts[DocumentHandler::FAILED] > 0 ? MassiveAction::ACTION_KO : MassiveAction::ACTION_OK);
        }

        $ma->addMessage(htmlescape(sprintf(
            __('PDF2Text: %1$d followup(s) added, %2$d file(s) without text, %3$d file(s) not readable (see files/_log/pdf2text.log).', 'pdf2text'),
            $totals[DocumentHandler::ADDED],
            $totals[DocumentHandler::EMPTY],
            $totals[DocumentHandler::FAILED],
        )));
    }
}
