<?php

use Illuminate\Encryption\Encrypter;

// First-extraction verification only; no Laravel boot, migrations, seeds or key generation.
$root = dirname(__DIR__);
try {
    $manifestFile = $root.'/TRANSFER-MANIFEST.json';
    if (! is_file($manifestFile)) {
        throw new RuntimeException('TRANSFER-MANIFEST.json is missing. Use the complete private transfer ZIP.');
    }
    $manifest = json_decode(file_get_contents($manifestFile), true, 32, JSON_THROW_ON_ERROR);
    if (($manifest['version'] ?? null) !== 1 || empty($manifest['files'])) {
        throw new RuntimeException('Unsupported transfer manifest.');
    }
    $prefix = realpath($root).DIRECTORY_SEPARATOR;
    foreach ($manifest['files'] as $name => $expected) {
        $path = realpath($root.'/'.$name);
        if (! $path || ! str_starts_with($path, $prefix) || ! is_file($path)
            || filesize($path) !== $expected['bytes'] || ! hash_equals($expected['sha256'], hash_file('sha256', $path))) {
            throw new RuntimeException('Missing or changed transfer file: '.$name);
        }
    }
    require $root.'/vendor/autoload.php';
    $env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    foreach (['APP_KEY' => 'application_key_fingerprint', 'EVALUATION_FINGERPRINT_KEY' => 'evaluation_key_fingerprint'] as $key => $fingerprint) {
        if (! hash_equals($manifest[$fingerprint], hash('sha256', $env[$key] ?? ''))) {
            throw new RuntimeException('The original application/evaluation keys were not preserved.');
        }
    }
    $db = new PDO('sqlite:'.$root.'/database/campus.sqlite');
    $db->exec('PRAGMA query_only=ON');
    if ($db->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
        throw new RuntimeException('SQLite integrity verification failed.');
    }
    foreach ($manifest['records'] as $table => $count) {
        if (! preg_match('/^[a-z_]+$/', $table) || (int) $db->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn() !== $count) {
            throw new RuntimeException('Database record verification failed.');
        }
    }
    $sample = $db->query('SELECT payload FROM background_tasks WHERE payload IS NOT NULL LIMIT 1')->fetchColumn();
    if ($sample) {
        $key = $env['APP_KEY'];
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        (new Encrypter($key, $manifest['cipher']))->decryptString($sample);
    }
    echo 'Transfer verified: '.count($manifest['files']).' files, consistent SQLite records, and original encryption keys.'.PHP_EOL;
    echo 'Snapshot: '.$manifest['created_at'].'; source revision: '.$manifest['revision'].PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Verification failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
