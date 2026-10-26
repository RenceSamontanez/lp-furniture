<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/** A plain mysqldump file taken before data:clear-test deletes anything. */
final class DatabaseBackup
{
    public static function binary(): ?string
    {
        // MYSQLDUMP_PATH=none switches the automatic backup off (the command then asks for --backup-taken).
        if (config('maintenance.mysqldump_path') === 'none') {
            return null;
        }

        $candidates = array_filter([
            config('maintenance.mysqldump_path'),
            config('database.connections.mysql.dump.dump_binary_path')
                ? rtrim((string) config('database.connections.mysql.dump.dump_binary_path'), '/\\') . DIRECTORY_SEPARATOR . 'mysqldump'
                : null,
            'mysqldump',
            dirname(PHP_BINARY, 2) . '/mysql/bin/mysqldump',
            'C:/xampp/mysql/bin/mysqldump',
            'C:/xampp_new/mysql/bin/mysqldump',
            '/opt/lampp/bin/mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ]);

        foreach ($candidates as $candidate) {
            try {
                $p = new Process([$candidate, '--version']);
                $p->setTimeout(10);
                $p->run();
                if ($p->isSuccessful()) {
                    return $candidate;
                }
            } catch (\Throwable) {
                // not runnable: try the next one
            }
        }

        return null;
    }

    /**
     * Writes the dump and returns its path, or null (with the reason in $error) when it could not be made.
     * The password goes through the environment, never the command line.
     */
    public static function create(?string &$error = null): ?string
    {
        $c = config('database.connections.' . config('database.default'));
        if (($c['driver'] ?? null) !== 'mysql') {
            $error = 'Automatic backup only supports MySQL.';

            return null;
        }

        $binary = self::binary();
        if ($binary === null) {
            $error = 'mysqldump was not found.';

            return null;
        }

        $dir = (string) config('maintenance.backup_dir');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            $error = "Could not create the backup folder {$dir}.";

            return null;
        }

        $file = $dir . DIRECTORY_SEPARATOR . $c['database'] . '-before-clear-' . date('Ymd-His') . '.sql';

        $p = new Process([
            $binary, '--host=' . $c['host'], '--port=' . $c['port'], '--user=' . $c['username'],
            '--single-transaction', '--routines', '--no-tablespaces', '--result-file=' . $file, $c['database'],
        ], null, ['MYSQL_PWD' => (string) ($c['password'] ?? '')]);
        $p->setTimeout(600);
        $p->run();

        if (!$p->isSuccessful() || !is_file($file) || filesize($file) < 100) {
            @unlink($file);
            $error = 'mysqldump failed: ' . trim($p->getErrorOutput());

            return null;
        }

        return $file;
    }

    public static function databaseName(): string
    {
        return DB::getDatabaseName();
    }
}
