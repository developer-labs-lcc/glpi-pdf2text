<?php

use Glpi\Kernel\Kernel;

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

// Test bootstrap: boots the GLPI kernel and opens a session for the user given as argv[1]
// (default: glpi). Tests run against the database of this GLPI instance: they only delete
// records they created themselves (see track() / finish()). The plugin must be active.

// The test output is buffered (printed at the end): GLPI's page header sends HTTP headers, which
// is only possible while nothing has been printed yet.
ob_start();

$glpi_root = dirname(__DIR__, 4);
chdir($glpi_root);
require $glpi_root . '/vendor/autoload.php';
$kernel = new Kernel('production');
$kernel->boot();

$login = $argv[1] ?? 'glpi';
$auth  = new Auth();
$auth->auth_succeded = true;
$auth->user = new User();
if (!$auth->user->getFromDBbyName($login)) {
    fwrite(STDERR, "user \"$login\" not found\n");
    exit(1);
}
Session::init($auth);
Session::changeActiveEntities(0, true);
// Tests check English texts, whatever the user's language setting is.
Session::loadLanguage('en_GB');
$_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
foreach (Plugin::getPlugins() as $key) {
    if (function_exists("plugin_init_$key")) {
        call_user_func("plugin_init_$key");
    }
}
if (!Plugin::isPluginActive('pdf2text')) {
    fwrite(STDERR, "plugin pdf2text is not active\n");
    exit(1);
}

$GLOBALS['pt_fail']     = 0;
$GLOBALS['pt_created']  = [];
$GLOBALS['pt_finished'] = false;

// Safety net: if a test dies before finish(), its own records are still removed.
register_shutdown_function(static function (): void {
    if ($GLOBALS['pt_finished']) {
        return;
    }
    echo "  (test ended unexpectedly – removing its own records)\n";
    purge_tracked();
    echo "FAILURES: " . max(1, $GLOBALS['pt_fail']) . "\n";
});

function check(string $label, bool $cond, string $extra = ''): void
{
    echo ($cond ? '  OK   ' : '  FAIL ') . $label . ($extra !== '' ? "  -> $extra" : '') . "\n";
    if (!$cond) {
        $GLOBALS['pt_fail']++;
    }
}

function section(string $title): void
{
    echo "== $title\n";
}

/**
 * Remembers an object created by the test, so that exactly this one is purged at the end.
 */
function track(string $itemtype, int $id): void
{
    if ($id > 0) {
        $GLOBALS['pt_created'][] = [$itemtype, $id];
    }
}

/**
 * Unique marker for names of test records, e.g. "PTTEST-6512ab".
 */
function run_id(): string
{
    static $id = null;
    return $id ??= 'PTTEST-' . substr(bin2hex(random_bytes(4)), 0, 7);
}

function finish(): never
{
    purge_tracked();
    $GLOBALS['pt_finished'] = true;
    echo "FAILURES: {$GLOBALS['pt_fail']}\n";
    exit($GLOBALS['pt_fail'] > 0 ? 1 : 0);
}

/**
 * Purges the tracked objects (reverse order) and the documents uploaded by this run.
 */
function purge_tracked(): void
{
    global $DB;

    foreach (array_reverse($GLOBALS['pt_created']) as [$itemtype, $id]) {
        $item = getItemForItemtype($itemtype);
        if ($item && $item->getFromDB($id)) {
            $ok = $item->delete(['id' => $id], true);
            echo $ok ? "  purged own test item $itemtype #$id\n" : "  NOT purged $itemtype #$id\n";
        }
    }
    $GLOBALS['pt_created'] = [];

    // Documents are not purged together with their ticket. upload() gives every file a name
    // starting with the run id, so exactly the documents of this run are found here.
    $docs = $DB->request([
        'SELECT' => ['id'],
        'FROM'   => Document::getTable(),
        'WHERE'  => ['filename' => ['LIKE', run_id() . '-%']],
    ]);
    foreach ($docs as $row) {
        (new Document())->delete(['id' => (int) $row['id']], true);
        echo "  purged own test document #{$row['id']}\n";
    }
}

/**
 * Copies a fixture to GLPI_TMP_DIR, where GLPI expects uploaded files ("_filename" input),
 * and returns the name relative to GLPI_TMP_DIR. The run id makes the content unique, so
 * GLPI does not reuse a document of an earlier run (duplicates are detected by checksum).
 */
function upload(string $fixture): string
{
    $name = run_id() . '-' . $fixture;
    $data = file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture);
    file_put_contents(GLPI_TMP_DIR . '/' . $name, $data . "\n%" . run_id() . '-' . bin2hex(random_bytes(4)) . "\n");
    return $name;
}

/**
 * Followups of an ITIL object that were written by the plugin.
 *
 * @return array<int, array<string, mixed>>
 */
function pdf_followups(CommonITILObject $item): array
{
    global $DB;

    return iterator_to_array($DB->request([
        'FROM'  => ITILFollowup::getTable(),
        'WHERE' => [
            'itemtype' => $item::class,
            'items_id' => $item->getID(),
            'content'  => ['LIKE', '%Text of the PDF attachment%'],
        ],
    ]), false);
}
