<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Called by build-campus-transfer.py. Secrets are written only inside its private output.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$destination = realpath($argv[1] ?? '');
if (! $destination || is_file($destination.'/.env') || is_file($destination.'/database/campus.sqlite')) {
    throw new RuntimeException('Choose a new, empty transfer destination.');
}
if (config('database.default') !== 'sqlite') {
    throw new RuntimeException('This transfer builder requires the active evaluation SQLite configuration.');
}
File::ensureDirectoryExists($destination.'/database');
DB::statement('VACUUM INTO ?', [$destination.'/database/campus.sqlite']);
$db = new PDO('sqlite:'.$destination.'/database/campus.sqlite');
if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
    throw new RuntimeException('The exported database failed integrity verification.');
}
if ((int) $db->query("SELECT COUNT(*) FROM background_tasks WHERE status IN ('queued','processing')")->fetchColumn()
    || (int) $db->query('SELECT COUNT(*) FROM jobs')->fetchColumn()) {
    throw new RuntimeException('Background work is still pending. Finish the queues and build a fresh transfer snapshot.');
}
$records = [];
foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
    if (! preg_match('/^[a-z_]+$/', $table)) {
        throw new RuntimeException('Unexpected database table name.');
    }
    $records[$table] = (int) $db->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn();
}
$env = Dotenv\Dotenv::parse(file_get_contents(base_path('.env')));
foreach (['SESSION_FILES', 'BACKUP_PATH', 'BACKUP_MYSQLDUMP', 'STUDENT_DB_MYSQL_BIN', 'STUDENT_DB_PHPMYADMIN_SOURCE', 'STUDENT_DB_LARAGON_PATHS', 'STUDENT_DB_PATH'] as $key) {
    unset($env[$key]);
}
$env = [...$env, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8020',
    'APP_KEY' => config('app.key'), 'APP_TIMEZONE' => config('app.timezone'),
    'EVALUATION_FINGERPRINT_KEY' => config('evaluations.fingerprint_key'),
    'DB_CONNECTION' => 'sqlite', 'DB_URL' => 'null', 'DB_DATABASE' => 'database/campus.sqlite',
    'SESSION_DRIVER' => 'file', 'SESSION_DOMAIN' => 'null', 'SESSION_SECURE_COOKIE' => 'false',
    'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'APP_MAINTENANCE_DRIVER' => 'file'];
$lines = [];
foreach ($env as $name => $value) {
    // Single-quoted dotenv values preserve literal $, # and spaces. Existing dotenv
    // secrets containing apostrophes use the escaped double-quoted representation.
    if (str_contains((string) $value, "'")) {
        $quoted = '"'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '\\n', '\\r'], (string) $value).'"';
    } else {
        $quoted = "'".$value."'";
    }
    // Boolean/null literals must stay unquoted for Laravel environment coercion.
    $lines[] = $name.'='.(in_array($value, ['true', 'false', 'null'], true) ? $value : $quoted);
}
file_put_contents($destination.'/.env', implode(PHP_EOL, $lines).PHP_EOL);
$parsed = Dotenv\Dotenv::parse(file_get_contents($destination.'/.env'));
foreach (['APP_KEY', 'EVALUATION_FINGERPRINT_KEY'] as $key) {
    if ($parsed[$key] !== $env[$key]) {
        throw new RuntimeException('The portable key could not be preserved exactly.');
    }
}
$metadata = ['created_at' => now()->setTimezone('Asia/Manila')->toIso8601String(),
    'application_key_fingerprint' => hash('sha256', config('app.key')),
    'evaluation_key_fingerprint' => hash('sha256', config('evaluations.fingerprint_key')),
    'cipher' => config('app.cipher'), 'records' => $records,
    'students' => (int) $db->query('SELECT COUNT(*) FROM users WHERE is_student=1 AND deleted_at IS NULL')->fetchColumn()];
file_put_contents($destination.'/snapshot-metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Consistent evaluation snapshot and original keys prepared privately.'.PHP_EOL;
