<?php

return [
    /*
    | Nightly backups: the database and the uploaded files (class-record
    | workbooks, student files) zipped into one file per day. Point BACKUP_PATH
    | at a second drive or a USB stick so a failed disk does not take the
    | backups with it. The desktop host runs the scheduler while it is open, and
    | `php artisan classroom:backup` makes one by hand.
    */
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),

    // mysqldump to use. Empty means: Laragon's newest MySQL, else mysqldump on PATH.
    'mysqldump' => env('BACKUP_MYSQLDUMP'),
];
