<?php

/**
 * PDF2Text – copies the text of PDF, Word and OpenDocument attachments into GLPI tickets, changes and problems.
 *
 * @link      https://developer-labs.com/en/glpi-development/
 * @copyright Developer Labs LLC
 * @license   GPL-3.0-or-later – see LICENSE
 * @version   1.2.0
 * @created   2026-10-22
 * @changed   2026-11-15
 */

// Integration with GLPI: new ticket with a PDF (as created by the mail collector), scanned
// PDF, non-PDF attachment, PDF in a reply followup of a solved ticket, PDF in a task, change,
// disabled item type, private flag and search by followup content; Word and OpenDocument files;
// no second followup for a file attached again; files attached before (processItem(), console
// command, massive action); the button at the attachments in the timeline.
//
// The plugin settings are changed for the test and restored.

require __DIR__ . '/bootstrap.php';

/** @var DBmysql $DB */
global $DB;

use GlpiPlugin\Pdf2text\AttachmentButton;
use GlpiPlugin\Pdf2text\Console\ProcessCommand;
use GlpiPlugin\Pdf2text\Controller\AttachmentController;
use GlpiPlugin\Pdf2text\DocumentHandler;
use GlpiPlugin\Pdf2text\MassiveActions;
use GlpiPlugin\Pdf2text\ProcessedFiles;
use GlpiPlugin\Pdf2text\Settings;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;

$rid = run_id();
$original_settings = [];
foreach (array_keys(Settings::DEFAULTS) as $key) {
    $original_settings[$key] = Settings::get($key);
}
register_shutdown_function(static fn() => Settings::set($original_settings));
Settings::set(['on_ticket' => 1, 'on_change' => 1, 'on_problem' => 1, 'is_private' => 1, 'type_pdf' => 1, 'type_docx' => 1, 'type_odt' => 1]);

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

Settings::set(['on_problem' => 1, 'is_private' => 1]);

section('Word and OpenDocument');
$office = $create(Ticket::class, 'Office ticket', ['change.docx', 'change.odt']);
$office_followups = followups_with($office, 'OKAPI-0815');
check('one followup each for DOCX and ODT', count($office_followups) === 2, (string) count($office_followups));
check('heading names the file', count(followups_with($office, 'Text of the attachment &quot;' . run_id() . '-change.docx')) === 1
    || count(followups_with($office, 'Text of the attachment "' . run_id() . '-change.docx')) === 1);
check('table row kept in the text', count(followups_with($office, "Core router R1\tOutage 15 min")) === 2);
Settings::set(['type_docx' => 0]);
$no_docx = $create(Ticket::class, 'DOCX off ticket', ['change.docx', 'change.odt']);
check('file type switched off: only the ODT is read', count(followups_with($no_docx, 'OKAPI-0815')) === 1);
Settings::set(['type_docx' => 1]);

section('same file attached again');
$again = $create(Ticket::class, 'Again ticket', []);
foreach ([1, 2] as $round) {
    // The same PDF in two reply mails (GLPI links the existing document again: same checksum)
    DocumentHandler::reset();
    (new ITILFollowup())->add([
        'itemtype'  => Ticket::class,
        'items_id'  => $again->getID(),
        'content'   => "Reply $round with the same PDF",
        '_filename' => [upload_same('change.pdf', $round)],
    ]);
}
check('same PDF in two reply mails: one text followup', count(pdf_followups($again)) === 1, (string) count(pdf_followups($again)));
DocumentHandler::reset();
(new ITILFollowup())->add([
    'itemtype'  => Ticket::class,
    'items_id'  => $again->getID(),
    'content'   => 'Reply with a new PDF of the same name',
    '_filename' => [upload('change.pdf')],
]);
check('new file with the same name: a second text followup', count(pdf_followups($again)) === 2, (string) count(pdf_followups($again)));
$rows = count($DB->request(['FROM' => ProcessedFiles::TABLE, 'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $again->getID()]]));
check('two files remembered for the ticket', $rows === 2, (string) $rows);

section('files attached before (processItem)');
Settings::set(['on_ticket' => 0]);
$before = $create(Ticket::class, 'Before ticket', ['change.pdf', 'change.odt', 'scan.pdf', 'notes.txt']);
check('type switched off: nothing added on creation', count(followups_with($before, '<pre>')) === 0);
Settings::set(['on_ticket' => 1]);
DocumentHandler::reset();
$task = new TicketTask();
$task_id = $task->add(['tickets_id' => $before->getID(), 'content' => 'Task with a DOCX', '_filename' => [upload('change.docx')]]);
check('DOCX in a new task is read at once', count(followups_with($before, 'OKAPI-0815')) === 1);
DocumentHandler::reset();
$dry = DocumentHandler::processItem($before, true);
check('dry run: PDF and ODT to read, DOCX read before', $dry[DocumentHandler::PENDING] === 3 && $dry[DocumentHandler::DONE] === 1, json_encode($dry) ?: '');
check('dry run adds nothing', count(followups_with($before, '<pre>')) === 1);
DocumentHandler::reset();
$counts = DocumentHandler::processItem($before);
check('processItem: PDF and ODT added, scan without text', $counts[DocumentHandler::ADDED] === 2 && $counts[DocumentHandler::EMPTY] === 1 && $counts[DocumentHandler::DONE] === 1, json_encode($counts) ?: '');
check('text followups now: PDF, ODT, DOCX', count(followups_with($before, '<pre>')) === 3);
DocumentHandler::reset();
$counts = DocumentHandler::processItem($before);
check('second run: everything read before', $counts[DocumentHandler::ADDED] === 0 && $counts[DocumentHandler::DONE] === 4, json_encode($counts) ?: '');

section('deleted text followup');
$pdf_followup = pdf_followups($before)[0];
(new ITILFollowup())->delete(['id' => $pdf_followup['id']], true);
DocumentHandler::reset();
$counts = DocumentHandler::processItem($before);
check('after deleting the text followup the PDF is read again', $counts[DocumentHandler::ADDED] === 1, json_encode($counts) ?: '');

section('followups of before the tracking');
$old = $create(Ticket::class, 'Old ticket', []);
Settings::set(['on_ticket' => 0]);
DocumentHandler::reset();
$old_doc_followup = (new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $old->getID(), 'content' => 'Mail with a PDF', '_filename' => [upload_same('change.pdf', 3)]]);
Settings::set(['on_ticket' => 1]);
// A followup as an earlier version wrote it, dated before the tracking started
$old_text = new ITILFollowup();
$old_text_id = (int) $old_text->add(['itemtype' => Ticket::class, 'items_id' => $old->getID(), 'content' => '<p><strong>Text des PDF-Anhangs „' . htmlescape(run_id() . '-3-change.pdf') . '“</strong></p><pre>old text</pre>', '_disablenotif' => true]);
$DB->update(ITILFollowup::getTable(), ['date_creation' => '2020-01-01 00:00:00'], ['id' => $old_text_id]);
DocumentHandler::reset();
$counts = DocumentHandler::processItem($old);
check('followup of an earlier version (German heading) recognised', $counts[DocumentHandler::DONE] === 1 && $counts[DocumentHandler::ADDED] === 0, json_encode($counts) ?: '');
$DB->update(ITILFollowup::getTable(), ['date_creation' => date('Y-m-d H:i:s')], ['id' => $old_text_id]);
DocumentHandler::reset();
$counts = DocumentHandler::processItem($old);
check('the same followup written after the tracking started does not count', $counts[DocumentHandler::ADDED] === 1, json_encode($counts) ?: '');

section('console command');
$cmd_ticket = $create(Ticket::class, 'Command ticket', []);
Settings::set(['on_ticket' => 0]);
DocumentHandler::reset();
(new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $cmd_ticket->getID(), 'content' => 'Mail', '_filename' => [upload('change.pdf')]]);
Settings::set(['on_ticket' => 1]);
$tester = new CommandTester(new ProcessCommand());
$tester->execute(['--itemtype' => 'ticket', '--id' => (string) $cmd_ticket->getID(), '--dry-run' => true]);
check('dry run reports the file', str_contains($tester->getDisplay(), '1 to read') && count(pdf_followups($cmd_ticket)) === 0, trim($tester->getDisplay()));
$tester->execute(['--itemtype' => 'Ticket', '--id' => (string) $cmd_ticket->getID()]);
check('command adds the text', $tester->getStatusCode() === 0 && count(pdf_followups($cmd_ticket)) === 1, trim($tester->getDisplay()));
$tester->execute(['--itemtype' => 'Ticket', '--id' => (string) $cmd_ticket->getID()]);
check('second run adds nothing', count(pdf_followups($cmd_ticket)) === 1 && str_contains($tester->getDisplay(), '1 read before'), trim($tester->getDisplay()));
$tester->execute(['--itemtype' => 'Computer']);
check('unknown type refused', $tester->getStatusCode() !== 0);

section('massive action');
$actions = MassiveActions::forItemtype(Ticket::class);
check('offered for tickets', count($actions) === 1);
Settings::set(['on_problem' => 0]);
check('not offered for a type switched off', MassiveActions::forItemtype(Problem::class) === []);
Settings::set(['on_problem' => 1]);
check('not offered for other types', MassiveActions::forItemtype(Computer::class) === []);
$ma_ticket = $create(Ticket::class, 'Massive ticket', []);
Settings::set(['on_ticket' => 0]);
DocumentHandler::reset();
(new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $ma_ticket->getID(), 'content' => 'Mail', '_filename' => [upload('change.odt')]]);
Settings::set(['on_ticket' => 1]);
DocumentHandler::reset();
$ma = new MassiveAction([
    // Step "process": GLPI has split "class:action" from the list into processor and action
    'action'        => MassiveActions::ACTION,
    'processor'     => MassiveActions::class,
    'items'         => [Ticket::class => [$ma_ticket->getID() => $ma_ticket->getID()]],
    'initial_items' => [Ticket::class => [$ma_ticket->getID() => $ma_ticket->getID()]],
    'action_name'   => 'PDF2Text',
    'is_deleted'    => 0,
], [], 'process');
MassiveActions::processMassiveActionsForOneItemtype($ma, new Ticket(), [$ma_ticket->getID()]);
check('processor and action recognised', $ma->getAction() === MassiveActions::ACTION, $ma->getAction());
check('massive action adds the text', count(followups_with($ma_ticket, 'OKAPI-0815')) === 1, json_encode($ma->results) ?: '');

section('button at the attachment');
$button_ticket = $create(Ticket::class, 'Button ticket', []);
$other_ticket  = $create(Ticket::class, 'Other ticket', []);
Settings::set(['on_ticket' => 0]);
DocumentHandler::reset();
(new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $button_ticket->getID(), 'content' => 'Mail', '_filename' => [upload('change.pdf')]]);
(new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $other_ticket->getID(), 'content' => 'Mail', '_filename' => [upload('change.odt')]]);
Settings::set(['on_ticket' => 1]);
$document_of = static function (CommonITILObject $item) use ($DB): int {
    $followups = array_column(iterator_to_array($DB->request(['SELECT' => ['id'], 'FROM' => ITILFollowup::getTable(), 'WHERE' => ['itemtype' => $item::class, 'items_id' => $item->getID()]]), false), 'id');
    $row = $DB->request(['SELECT' => ['documents_id'], 'FROM' => Document_Item::getTable(), 'WHERE' => ['itemtype' => ITILFollowup::class, 'items_id' => $followups]])->current();
    return (int) ($row['documents_id'] ?? 0);
};
$pdf_id   = $document_of($button_ticket);
$other_id = $document_of($other_ticket);
check('page of the ticket recognised', AttachmentButton::pageItem('/glpi/front/ticket.form.php?id=' . $button_ticket->getID())?->getID() === $button_ticket->getID());
check('other pages get no button', AttachmentButton::pageItem('/glpi/front/ticket.php') === null && AttachmentButton::pageItem('/glpi/front/computer.form.php?id=1') === null);
$tag = AttachmentButton::headerTag('/glpi/front/ticket.form.php?id=' . $button_ticket->getID(), '/glpi');
$tag_data = json_decode($tag['properties']['content'] ?? '{}', true);
check('meta tag with address, ticket and file types', ($tag_data['url'] ?? '') === '/glpi/plugins/pdf2text/attachment'
    && ($tag_data['items_id'] ?? 0) === $button_ticket->getID() && in_array('pdf', $tag_data['extensions'] ?? [], true), json_encode($tag_data) ?: '');

$click = static function (array $data): array {
    DocumentHandler::reset();
    $response = (new AttachmentController())->attachment(Request::create('/plugins/pdf2text/attachment', 'POST', $data));
    return ['code' => $response->getStatusCode()] + (json_decode((string) $response->getContent(), true) ?: []);
};
$data = ['itemtype' => Ticket::class, 'items_id' => $button_ticket->getID(), 'documents_id' => $pdf_id];
$answer = $click($data);
check('click: text added', ($answer['status'] ?? '') === 'added' && count(pdf_followups($button_ticket)) === 1, json_encode($answer) ?: '');
$answer = $click($data);
check('second click: asks before adding again', ($answer['status'] ?? '') === 'done' && count(pdf_followups($button_ticket)) === 1, json_encode($answer) ?: '');
$answer = $click($data + ['force' => '1']);
check('confirmed: text added again', ($answer['status'] ?? '') === 'added' && count(pdf_followups($button_ticket)) === 2, json_encode($answer) ?: '');
$answer = $click(['itemtype' => Ticket::class, 'items_id' => $button_ticket->getID(), 'documents_id' => $other_id]);
check('file of another ticket refused ("not found")', ($answer['code'] ?? 0) === 404 && count(followups_with($button_ticket, 'OKAPI-0815')) === 0, json_encode($answer) ?: '');
$answer = $click(['itemtype' => Computer::class, 'items_id' => 1, 'documents_id' => $pdf_id]);
check('other item type refused', ($answer['code'] ?? 0) === 403);
$followup_rights = $_SESSION['glpiactiveprofile']['followup'];
$_SESSION['glpiactiveprofile']['followup'] = 0;
$answer = $click($data + ['force' => '1']);
check('without the right to add followups: refused', ($answer['code'] ?? 0) === 403 && count(pdf_followups($button_ticket)) === 2, json_encode($answer) ?: '');
check('without the right: no button on the page', AttachmentButton::headerTag('/glpi/front/ticket.form.php?id=' . $button_ticket->getID(), '/glpi') === null);
$_SESSION['glpiactiveprofile']['followup'] = $followup_rights;
// Messages for after the reload, noted by the clicks above
$_SESSION['MESSAGE_AFTER_REDIRECT'] = [];

section('purge');
$purge_id = $before->getID();
check('rows exist before purge', count($DB->request(['FROM' => ProcessedFiles::TABLE, 'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $purge_id]])) > 0);
$before->delete(['id' => $purge_id], true);
check('rows removed with the ticket', count($DB->request(['FROM' => ProcessedFiles::TABLE, 'WHERE' => ['itemtype' => Ticket::class, 'items_id' => $purge_id]])) === 0);

finish();
