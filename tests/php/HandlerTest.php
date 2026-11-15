<?php

/**
 * PDF2Text – copies the text of PDF attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.0.1
 * @created   2026-10-22
 * @changed   2026-11-15
 */

// Integration with GLPI: new ticket with a PDF (as created by the mail collector), scanned
// PDF, non-PDF attachment, PDF in a reply followup of a solved ticket, PDF in a task, change,
// disabled item type, private flag and search by followup content.
//
// The plugin settings are changed for the test and restored.

require __DIR__ . '/bootstrap.php';

use GlpiPlugin\Pdf2text\DocumentHandler;
use GlpiPlugin\Pdf2text\Settings;

$rid = run_id();
$original_settings = [];
foreach (array_keys(Settings::DEFAULTS) as $key) {
    $original_settings[$key] = Settings::get($key);
}
register_shutdown_function(static fn() => Settings::set($original_settings));
Settings::set(['on_ticket' => 1, 'on_change' => 1, 'on_problem' => 1, 'is_private' => 1]);

/**
 * Creates an ITIL object with the given fixtures attached, the same way the mail collector does.
 */
$create = static function (string $itemtype, string $name, array $fixtures, array $extra = []): CommonITILObject {
    DocumentHandler::reset();
    $item = new $itemtype();
    $id   = $item->add([
        'name'        => "$name " . run_id(),
        'content'     => 'Test of the PDF2Text plugin',
        'entities_id' => 0,
        '_filename'   => array_map('upload', $fixtures),
    ] + $extra);
    track($itemtype, (int) $id);
    $item->getFromDB((int) $id);
    return $item;
};

section('new ticket with a PDF attachment (mail collector path)');
$ticket = $create(Ticket::class, 'PDF ticket', ['change.pdf']);
$followups = pdf_followups($ticket);
check('ticket created', $ticket->getID() > 0);
check('exactly one followup with the PDF text', count($followups) === 1, (string) count($followups));
$content = $followups[0]['content'] ?? '';
check('followup contains the search token', str_contains($content, 'ZEBRA-4711'));
check('followup contains umlauts', str_contains($content, 'Kärnten'));
check('followup names the file', str_contains($content, 'change.pdf'));
check('text is in a <pre> block (layout kept)', str_contains($content, '<pre>'));
check('followup is private', (int) ($followups[0]['is_private'] ?? 0) === 1);
check('ticket status unchanged (new)', (int) $ticket->fields['status'] === CommonITILObject::INCOMING);

section('search by followup content');
$search = Search::getDatas(Ticket::class, [
    'criteria' => [
        ['field' => 25, 'searchtype' => 'contains', 'value' => 'ZEBRA-4711'],
        ['link' => 'AND', 'field' => 1, 'searchtype' => 'contains', 'value' => $rid],
    ],
]);
check('ticket found with "Followups > Description" = PDF text', (int) $search['data']['totalcount'] === 1, (string) $search['data']['totalcount']);

section('attachments without text');
$log      = GLPI_LOG_DIR . '/pdf2text.log';
$log_size = is_file($log) ? filesize($log) : 0;
$scan     = $create(Ticket::class, 'Scan ticket', ['scan.pdf']);
check('scanned PDF: no followup', count(pdf_followups($scan)) === 0);
// Make sure the PDF was really read and found empty (and not skipped because of an error).
clearstatcache();
$new_log = is_file($log) ? (string) file_get_contents($log, false, null, $log_size) : '';
check('scanned PDF: logged as "no text layer"', str_contains($new_log, 'no text layer') && !str_contains($new_log, 'could not'), trim($new_log));
$txt = $create(Ticket::class, 'Text ticket', ['notes.txt']);
check('non-PDF attachment: no followup', count(pdf_followups($txt)) === 0);

section('PDF in a reply followup of a solved ticket');
$solved = $create(Ticket::class, 'Solved ticket', []);
$solved->update(['id' => $solved->getID(), 'status' => CommonITILObject::SOLVED]);
DocumentHandler::reset();
$reply = new ITILFollowup();
$reply_id = $reply->add([
    'itemtype'  => Ticket::class,
    'items_id'  => $solved->getID(),
    'content'   => 'Reply mail with a PDF',
    '_filename' => [upload('change.pdf')],
    '_no_reopen' => true,
]);
check('reply followup created', $reply_id > 0);
check('text followup added to the ticket', count(pdf_followups($solved)) === 1);
$solved->getFromDB($solved->getID());
check('ticket stays solved', (int) $solved->fields['status'] === CommonITILObject::SOLVED, (string) $solved->fields['status']);

section('PDF in a task');
$task_ticket = $create(Ticket::class, 'Task ticket', []);
DocumentHandler::reset();
$task = new TicketTask();
$task_id = $task->add([
    'tickets_id' => $task_ticket->getID(),
    'content'    => 'Task with a PDF',
    '_filename'  => [upload('change.pdf')],
]);
check('task created', $task_id > 0);
check('text followup added to the ticket of the task', count(pdf_followups($task_ticket)) === 1);

section('change and problem');
$change = $create(Change::class, 'PDF change', ['change.pdf']);
check('change: text followup added', count(pdf_followups($change)) === 1);

Settings::set(['on_problem' => 0, 'is_private' => 0]);
$problem = $create(Problem::class, 'PDF problem', ['change.pdf']);
check('problem with setting off: no followup', count(pdf_followups($problem)) === 0);
$public = $create(Ticket::class, 'Public ticket', ['change.pdf']);
check('setting "private" off: public followup', (int) (pdf_followups($public)[0]['is_private'] ?? 1) === 0);

finish();
