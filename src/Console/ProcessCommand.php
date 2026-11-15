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

namespace GlpiPlugin\Pdf2text\Console;

use CommonITILObject;
use DBmysql;
use Glpi\Console\AbstractCommand;
use GlpiPlugin\Pdf2text\Cast;
use GlpiPlugin\Pdf2text\DocumentHandler;
use GlpiPlugin\Pdf2text\ProcessedFiles;
use GlpiPlugin\Pdf2text\Settings;
use Plugin;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function Safe\preg_match;

/**
 * php bin/console plugins:pdf2text:process
 *
 * Reads the attachments of existing tickets, changes and problems, e.g. those attached before
 * the plugin was enabled, and adds their text as followups. Files read before are skipped
 * (see ProcessedFiles), so the command can be run again at any time.
 *
 * GLPI finds the command by its file name (…Command.php in src/) and its name
 * (plugins:pdf2text:…).
 */
final class ProcessCommand extends AbstractCommand
{
    /** Number of ITIL objects read from the database at a time (keeps the memory use low). */
    private const BATCH = 200;

    protected function configure(): void
    {
        parent::configure();

        $this->setName('plugins:pdf2text:process');
        $this->setDescription('Adds the text of attachments of existing tickets, changes and problems (PDF2Text).');
        $this->addOption('itemtype', 't', InputOption::VALUE_REQUIRED, 'Only this type: Ticket, Change or Problem (default: all types switched on in the settings)');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only the object with this ID (together with --itemtype)');
        $this->addOption('since', 's', InputOption::VALUE_REQUIRED, 'Only objects opened on or after this day (YYYY-MM-DD)');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Stop after this number of objects (default: no limit)');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count the files that would be read, add nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!Plugin::isPluginActive('pdf2text')) {
            $output->writeln('<error>The plugin PDF2Text is not active.</error>');
            return self::FAILURE;
        }
        if (!$DB->tableExists(ProcessedFiles::TABLE, false)) {
            // Without the table, files read before would not be recognised
            $output->writeln('<error>Update the plugin first (Setup > Plugins, or "php bin/console plugin:install pdf2text").</error>');
            return self::FAILURE;
        }

        // Types to process: the one asked for, or all switched on in the settings
        $itemtypes = array_values(array_filter(Settings::ITEMTYPES, static fn(string $type) => Settings::isEnabledFor($type)));
        $asked = Cast::string($input->getOption('itemtype'));
        if ($asked !== '') {
            // Case does not matter ("ticket" = "Ticket")
            $match = array_values(array_filter(Settings::ITEMTYPES, static fn(string $type) => strcasecmp($type, $asked) === 0));
            if ($match === []) {
                $output->writeln(sprintf('<error>Unknown type "%s" (Ticket, Change or Problem).</error>', $asked));
                return self::FAILURE;
            }
            if (!Settings::isEnabledFor($match[0])) {
                $output->writeln(sprintf('<error>%s is switched off in the settings of PDF2Text.</error>', $match[0]));
                return self::FAILURE;
            }
            $itemtypes = $match;
        }

        $id = Cast::int($input->getOption('id'));
        if ($id > 0 && $asked === '') {
            $output->writeln('<error>--id needs --itemtype.</error>');
            return self::FAILURE;
        }
        $since = Cast::string($input->getOption('since'));
        // YYYY-MM-DD: four digits, dash, two digits, dash, two digits
        if ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) !== 1) {
            $output->writeln('<error>--since needs a day as YYYY-MM-DD.</error>');
            return self::FAILURE;
        }
        $limit   = Cast::int($input->getOption('limit'));
        $dry_run = (bool) $input->getOption('dry-run');

        $totals = [DocumentHandler::ADDED => 0, DocumentHandler::EMPTY => 0, DocumentHandler::DONE => 0, DocumentHandler::PENDING => 0, DocumentHandler::FAILED => 0];
        $objects = 0;
        foreach ($itemtypes as $itemtype) {
            $item = getItemForItemtype($itemtype);
            if (!$item instanceof CommonITILObject) {
                continue;
            }
            $where = $id > 0 ? ['id' => $id] : [];
            if ($since !== '') {
                $where['date'] = ['>=', $since . ' 00:00:00'];
            }
            // In batches by ID: the next batch starts after the last ID of the previous one
            $last_id = 0;
            do {
                $rows = iterator_to_array($DB->request([
                    'SELECT' => ['id'],
                    'FROM'   => $item::getTable(),
                    'WHERE'  => $where + [['id' => ['>', $last_id]]],
                    'ORDER'  => 'id',
                    'LIMIT'  => self::BATCH,
                ]), false);
                foreach ($rows as $row) {
                    $last_id = Cast::int(is_array($row) ? ($row['id'] ?? 0) : 0);
                    if ($limit > 0 && $objects >= $limit) {
                        break 3;
                    }
                    $object = getItemForItemtype($itemtype);
                    if (!$object instanceof CommonITILObject || !$object->getFromDB($last_id)) {
                        continue;
                    }
                    $objects++;
                    $counts = DocumentHandler::processItem($object, $dry_run);
                    foreach ($counts as $result => $count) {
                        $totals[$result] += $count;
                    }
                    // One line per object with something new, the others only with -v
                    if ($counts[DocumentHandler::ADDED] + $counts[DocumentHandler::PENDING] + $counts[DocumentHandler::FAILED] > 0 || $output->isVerbose()) {
                        $output->writeln(sprintf('%s #%d: %s', $itemtype, $last_id, self::summary($counts)));
                    }
                }
                // Forget the files of this batch (only needed within one object)
                DocumentHandler::reset();
            } while (count($rows) === self::BATCH);
        }

        $output->writeln(sprintf('%d object(s)%s: %s', $objects, $dry_run ? ' (dry run, nothing added)' : '', self::summary($totals)));
        if ($totals[DocumentHandler::FAILED] > 0) {
            $output->writeln('<comment>Files that could not be read are listed in files/_log/pdf2text.log.</comment>');
        }

        return self::SUCCESS;
    }

    /**
     * E.g. "2 added, 1 without text, 3 read before".
     *
     * @param array<string, int> $counts
     */
    private static function summary(array $counts): string
    {
        $labels = [
            DocumentHandler::ADDED   => 'added',
            DocumentHandler::PENDING => 'to read',
            DocumentHandler::EMPTY   => 'without text',
            DocumentHandler::DONE    => 'read before',
            DocumentHandler::FAILED  => 'not readable',
        ];
        $parts = [];
        foreach ($labels as $result => $label) {
            if (($counts[$result] ?? 0) > 0) {
                $parts[] = $counts[$result] . ' ' . $label;
            }
        }

        return $parts === [] ? 'no files' : implode(', ', $parts);
    }
}
