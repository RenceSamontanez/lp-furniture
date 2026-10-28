<?php

namespace App\Console\Commands;

use App\Support\Luma\UnansweredQuestions;
use App\Support\StorageHealth;
use App\Support\UploadFolders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupStorage extends Command
{
    protected $signature = 'storage:cleanup
        {--force : Really delete. Without it this only prints what WOULD be removed}
        {--notifications : Also remove read notifications older than the configured days (default 60)}
        {--failed-jobs : Also remove failed jobs older than the configured days (default 14)}
        {--sessions : Also remove expired sessions (database session driver)}';

    protected $description = 'Removes orphan uploads (no database row, older than 24 h) and expired temporary files; optionally old read notifications, failed jobs and expired sessions. Dry run by default.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $hours = (int) config('maintenance.orphan_age_hours', 24);
        $verb = $force ? 'Removed' : 'Would remove';

        $orphans = UploadFolders::orphans($hours);
        $temp = UploadFolders::expiredTemp($hours);

        $orphanCount = array_sum(array_map('count', $orphans));
        $this->line("{$verb} orphan uploads (no database row, older than {$hours} h): <info>{$orphanCount}</info>");
        foreach ($orphans as $key => $files) {
            $this->line("    {$key}: " . count($files));
            foreach ($files as $f) {
                $this->line('      ' . $f, null, 'v');
            }
        }
        $this->line("{$verb} expired temporary files: <info>" . count($temp) . '</info>');

        $rows = [];
        if ($this->option('notifications') && Schema::hasTable('notifications')) {
            $days = (int) config('maintenance.read_notification_days', 60);
            $q = DB::table('notifications')->where('is_read', true)->where('created_at', '<', now()->subDays($days));
            $rows['read notifications older than ' . $days . ' days'] = $force ? $q->delete() : $q->count();
        }
        if ($this->option('failed-jobs') && Schema::hasTable('failed_jobs')) {
            $days = (int) config('maintenance.failed_job_days', 14);
            $q = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($days));
            $rows['failed jobs older than ' . $days . ' days'] = $force ? $q->delete() : $q->count();
        }
        if ($this->option('sessions') && config('session.driver') === 'database' && Schema::hasTable(config('session.table', 'sessions'))) {
            $q = DB::table(config('session.table', 'sessions'))->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp());
            $rows['expired sessions'] = $force ? $q->delete() : $q->count();
        }
        // Luma's unanswered-question list: dismissed and taught questions go after 30 days (always part of the cleanup).
        if (Schema::hasTable('luma_unanswered_questions')) {
            $rows['dismissed or taught Luma questions older than 30 days'] = UnansweredQuestions::purge(!$force);
        }
        foreach ($rows as $label => $n) {
            $this->line("{$verb} {$label}: <info>{$n}</info>");
        }

        if (!$force) {
            $this->newLine();
            $this->comment('DRY RUN: nothing was changed. Run with --force to apply.');

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($orphans as $files) {
            foreach ($files as $f) {
                $deleted += UploadFolders::delete($f) ? 1 : 0;
            }
        }
        foreach ($temp as $f) {
            $deleted += UploadFolders::delete($f) ? 1 : 0;
        }
        UploadFolders::pruneEmptyDirs();

        StorageHealth::recordCleanup(['files' => $deleted] + $rows);
        $this->info("Done. Files deleted: {$deleted}.");

        return self::SUCCESS;
    }
}
