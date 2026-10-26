<?php

/*
 | Settings for `data:clear-test`, `storage:cleanup` and the admin "System health" page.
 | The three roots are the ONLY places those tools ever delete from (tests point them at a temp folder).
 */
return [

    'roots' => [
        // private uploads (the "local" and "private" disks)
        'private' => storage_path('app/private'),
        // legacy public disk (storage/app/public, reachable through public/storage)
        'public_disk' => storage_path('app/public'),
        // public/ (only public/images/ai-designs inside it is ever touched; product and admin images are not)
        'public' => public_path(),
    ],

    // A file with no database row is only removed once it is older than this (an upload in progress has no row yet).
    'orphan_age_hours' => 24,

    // storage:cleanup optional items
    'read_notification_days' => 60,
    'failed_job_days' => 14,

    // database backups made by data:clear-test (customer data inside: keep it out of public/ and storage/app)
    'backup_dir' => storage_path('backups'),
    'mysqldump_path' => env('MYSQLDUMP_PATH'),

    // when storage:cleanup last ran (shown on System health)
    'state_file' => storage_path('app/maintenance-state.json'),

    // data:clear-test writes the list of what it deleted here
    'log_dir' => storage_path('logs'),
];
