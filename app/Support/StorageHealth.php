<?php

namespace App\Support;

/** Numbers for the admin "System health" page and the record of the last storage:cleanup run. */
final class StorageHealth
{
    private static function stateFile(): string
    {
        return (string) config('maintenance.state_file');
    }

    public static function recordCleanup(array $summary): void
    {
        $file = self::stateFile();
        @mkdir(dirname($file), 0755, true);
        @file_put_contents($file, json_encode(['at' => date('c'), 'summary' => $summary]));
    }

    /** @return array{at: \Carbon\Carbon, summary: array}|null */
    public static function lastCleanup(): ?array
    {
        $file = self::stateFile();
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data) || empty($data['at'])) {
            return null;
        }

        return ['at' => \Carbon\Carbon::parse($data['at']), 'summary' => $data['summary'] ?? []];
    }

    /** @return array{folders: list<array>, bytes: int, files: int, orphans: int, orphan_bytes: int} */
    public static function report(): array
    {
        $folders = [];
        $bytes = $files = 0;
        foreach (UploadFolders::folders() as $key => $f) {
            $u = UploadFolders::usage($key);
            $folders[] = ['label' => $f['label'], 'files' => $u['files'], 'bytes' => $u['bytes']];
            $bytes += $u['bytes'];
            $files += $u['files'];
        }

        $orphans = 0;
        $orphanBytes = 0;
        foreach (UploadFolders::orphans() as $list) {
            $orphans += count($list);
            $orphanBytes += array_sum(array_map(fn ($f) => (int) @filesize($f), $list));
        }

        return ['folders' => $folders, 'bytes' => $bytes, 'files' => $files, 'orphans' => $orphans, 'orphan_bytes' => $orphanBytes];
    }
}
