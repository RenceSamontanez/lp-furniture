<?php

namespace App\Console\Commands;

use App\Support\DatabaseBackup;
use App\Support\TestDataCleaner;
use App\Support\UploadFolders;
use Illuminate\Console\Command;

class ClearTestData extends Command
{
    protected $signature = 'data:clear-test
        {--force : Really delete. Without it this only prints what WOULD be removed}
        {--confirm= : Type CLEAR here instead of at the prompt (non-interactive use)}
        {--i-know-this-is-production : Required as well when APP_ENV is production}
        {--backup-taken : Skip the automatic backup because you already took one yourself}';

    protected $description = 'Removes test data (customers, orders, payments, designs, uploads...) before going live. Dry run by default; --force to apply.';

    public function handle(): int
    {
        $db = DatabaseBackup::databaseName();
        $this->line("Database: <info>{$db}</info>   Environment: <info>" . app()->environment() . '</info>');
        $this->newLine();

        $counts = TestDataCleaner::tableCounts();
        $folders = UploadFolders::folders();
        $usage = array_map(fn ($k) => UploadFolders::usage($k), array_combine(array_keys($folders), array_keys($folders)));

        $this->printPlan($counts, $usage, $folders);

        if (!$this->option('force')) {
            $this->newLine();
            $this->comment('DRY RUN: nothing was changed. Run with --force to apply.');

            return self::SUCCESS;
        }

        if (app()->environment('production') && !$this->option('i-know-this-is-production')) {
            $this->error('Refusing to run: APP_ENV is production. Add --i-know-this-is-production if you really mean it.');

            return self::FAILURE;
        }

        $typed = (string) $this->option('confirm');
        if ($typed === '' && $this->input->isInteractive()) {
            $typed = (string) $this->ask('This deletes the data listed above from "' . $db . '". Type CLEAR to continue');
        }
        if ($typed !== 'CLEAR') {
            $this->error('Not confirmed (the word must be exactly CLEAR). Nothing was changed.');

            return self::FAILURE;
        }

        // 1. backup
        if ($this->option('backup-taken')) {
            $this->warn('Backup skipped: you said you took one yourself.');
        } else {
            $error = null;
            $file = DatabaseBackup::create($error);
            if ($file === null) {
                $this->error("No backup could be made ({$error}).");
                $this->line('Take one manually first (for example: mysqldump -u root -p ' . $db . ' > backup.sql),');
                $this->line('then run this again with --backup-taken. Nothing was changed.');

                return self::FAILURE;
            }
            $this->info('Backup written: ' . $file);
        }

        // 2. the list of files, written before anything is deleted
        $logDir = (string) config('maintenance.log_dir');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $listFile = $logDir . DIRECTORY_SEPARATOR . 'data-clear-' . date('Ymd-His') . '.txt';
        $toDelete = [];
        foreach (array_keys($folders) as $key) {
            foreach (UploadFolders::files($key) as $f) {
                $toDelete[] = $f;
            }
        }
        $lines = ["data:clear-test on database {$db} at " . date('c'), '', 'ROWS TO DELETE'];
        foreach ($counts as $t => $n) {
            $lines[] = "  {$t}: {$n}";
        }
        $lines[] = '';
        $lines[] = 'FILES TO DELETE (' . count($toDelete) . ')';
        foreach ($toDelete as $f) {
            $lines[] = '  ' . $f;
        }
        file_put_contents($listFile, implode(PHP_EOL, $lines) . PHP_EOL);

        // 3. rows: one transaction, all or nothing
        try {
            $removed = TestDataCleaner::clearRows();
        } catch (\Throwable $e) {
            $this->error('The database part failed and was rolled back, nothing was deleted: ' . $e->getMessage());
            file_put_contents($listFile, PHP_EOL . 'FAILED AND ROLLED BACK: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);

            return self::FAILURE;
        }
        $this->info('Rows removed: ' . array_sum($removed) . ' in ' . count($removed) . ' tables.');

        // 4. files (only now that the rows are gone)
        $gone = 0;
        foreach ($toDelete as $f) {
            if (UploadFolders::delete($f)) {
                $gone++;
            }
        }
        UploadFolders::pruneEmptyDirs();
        $this->info("Files removed: {$gone} of " . count($toDelete) . '.');

        // 5. counters
        $reset = TestDataCleaner::resetCounters();
        $this->info('Id counters reset on ' . count($reset) . ' tables (users continues after the kept admin ids). Order, invoice, ticket and refund numbers restart because they are counted from their (now empty) tables.');

        file_put_contents($listFile, PHP_EOL . "DONE: rows removed " . array_sum($removed) . ", files removed {$gone}." . PHP_EOL, FILE_APPEND);
        $this->line('Deleted-items list: ' . $listFile);

        return self::SUCCESS;
    }

    private function printPlan(array $counts, array $usage, array $folders): void
    {
        $this->line('<comment>WOULD BE REMOVED: database rows</comment>');
        $this->table(['Table', 'Rows'], array_map(
            fn ($t, $n) => [$t === 'users' ? 'users (role = customer only)' : $t, $n],
            array_keys($counts), $counts
        ));

        $this->line('<comment>WOULD BE REMOVED: files (every file in these folders)</comment>');
        $rows = [];
        foreach ($folders as $key => $f) {
            $rows[] = [$f['path'], $usage[$key]['files'], UploadFolders::human($usage[$key]['bytes'])];
        }
        $this->table(['Folder', 'Files', 'Size'], $rows);

        $this->line('<info>KEPT: database tables</info>');
        $kept = TestDataCleaner::keptCounts();
        $this->table(['Table', 'Rows kept', 'What'], array_map(
            fn ($t, $n) => [$t, $n, TestDataCleaner::KEPT[$t]],
            array_keys($kept), $kept
        ));

        $this->line('<info>KEPT: folders (never touched)</info>');
        $this->table(['Folder', 'What'], array_map(fn ($p, $w) => [$p, $w], array_keys(UploadFolders::KEPT_FOLDERS), UploadFolders::KEPT_FOLDERS));

        $other = TestDataCleaner::untouchedTables();
        if ($other) {
            $this->line('<info>Not listed anywhere, so left alone:</info> ' . implode(', ', $other));
        }
    }
}
