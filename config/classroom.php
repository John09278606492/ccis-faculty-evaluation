<?php

return [
    // Shared hosting can refresh automatically without a persistent WebSocket server.
    'live_transport' => env('CLASSROOM_LIVE_TRANSPORT', 'reverb'),

    // Empty host uses the page hostname so other LAN devices never connect to their own localhost.
    'websocket_host' => env('CLASSROOM_WEBSOCKET_HOST'),
    'websocket_port' => (int) env('CLASSROOM_WEBSOCKET_PORT', env('REVERB_PORT', 8085)),
    'websocket_scheme' => env('CLASSROOM_WEBSOCKET_SCHEME'),

    /*
    | Student database workspaces: a second MySQL server, separate from the
    | application's own database, where each student gets their own account and
    | databases, used through phpMyAdmin. It listens on 127.0.0.1 only, so
    | students reach it solely through the phpMyAdmin copy this app serves.
    | Set it up with `php artisan classroom:student-db install`.
    */
    'student_db' => [
        'port' => (int) env('STUDENT_DB_PORT', 3307),

        // Runtime files: MySQL data, the phpMyAdmin copy, sign-on tokens and logs.
        'path' => env('STUDENT_DB_PATH', storage_path('app/student-db')),

        // MySQL's bin folder and the phpMyAdmin folder to copy. Empty means: use the
        // newest ones found in these Laragon folders.
        'mysql_bin' => env('STUDENT_DB_MYSQL_BIN'),
        'phpmyadmin_source' => env('STUDENT_DB_PHPMYADMIN_SOURCE'),
        'laragon_paths' => array_filter(explode(';', env('STUDENT_DB_LARAGON_PATHS', 'C:\laragon;D:\laragon'))),

        // Where browsers reach phpMyAdmin. The desktop host serves it on the classroom
        // port under /phpmyadmin; `classroom:student-db serve --phpmyadmin-port=` is for development.
        'phpmyadmin_url' => env('STUDENT_DB_PHPMYADMIN_URL', '/phpmyadmin'),

        // Limits for every student account.
        'max_user_connections' => (int) env('STUDENT_DB_MAX_CONNECTIONS', 5),
        'max_queries_per_hour' => (int) env('STUDENT_DB_MAX_QUERIES_PER_HOUR', 20000),
    ],
];
