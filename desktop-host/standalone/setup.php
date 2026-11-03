<?php

use App\Models\Campus;
use App\Models\User;
use App\Services\StudentDatabase\StudentDbServer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

// Private runtime helper. Input arrives on stdin, never in process arguments.
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$action = $input['action'];
$root = $input['project'];
if ($action === 'database-init') {
    $dsn = 'mysql:host=127.0.0.1;port='.(int) $input['port'];
    try {
        $pdo = new PDO($dsn, 'root', $input['rootPassword'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1045) {
            throw $e;
        }
        $pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$pdo->quote($input['rootPassword']));
    }
    $pdo->exec('CREATE DATABASE IF NOT EXISTS ccis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $password = $pdo->quote($input['appPassword']);
    $pdo->exec("CREATE USER IF NOT EXISTS 'ccis'@'127.0.0.1' IDENTIFIED BY $password");
    $pdo->exec("ALTER USER 'ccis'@'127.0.0.1' IDENTIFIED BY $password");
    $pdo->exec("GRANT ALL PRIVILEGES ON ccis.* TO 'ccis'@'127.0.0.1'");
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'archive') {
    $zip = new ZipArchive;
    if ($zip->open($input['target'].'.part', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create backup.');
    }
    $zip->addFromString('manifest.json', json_encode(['format' => 1, 'mysqlSeries' => '8.0', 'createdAt' => date(DATE_ATOM)]));
    foreach ($input['sources'] as $name => $source) {
        if (is_file($source)) {
            $zip->addFile($source, $name);
        } elseif (is_dir($source)) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($files as $file) {
                if ($file->isLink()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
                if ($file->isDir()) {
                    $zip->addEmptyDir($name.'/'.$relative);
                } else {
                    $zip->addFile($file->getPathname(), $name.'/'.$relative);
                }
            }
        }
    }
    if (! $zip->close() || ! rename($input['target'].'.part', $input['target'])) {
        throw new RuntimeException('Backup could not be completed.');
    }
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'extract') {
    $zip = new ZipArchive;
    if ($zip->open($input['source']) !== true) {
        throw new RuntimeException('Invalid backup ZIP.');
    }
    $manifest = json_decode($zip->getFromName('manifest.json') ?: '', true);
    if (($manifest['format'] ?? null) !== 1 || ($manifest['mysqlSeries'] ?? '') !== '8.0') {
        throw new RuntimeException('This is not a compatible CCIS desktop backup.');
    }
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        $name = $entry['name'];
        $zip->getExternalAttributesIndex($i, $opsys, $attributes);
        if (str_contains($name, '\\') || str_contains($name, ':') || preg_match('~(^|/)\.\.(/|$)|^/|\x00~', $name)
            || (($attributes >> 16) & 0170000) === 0120000
            || ! preg_match('~^(manifest\.json|instance\.json|application/\.env|mysql/[^\x00]+|application/storage/app/[^\x00]+)$~', $name)) {
            throw new RuntimeException('Unsafe file in backup.');
        }
        $total += $entry['size'];
        if ($total > 50 * 1024 * 1024 * 1024) {
            throw new RuntimeException('Backup exceeds the 50 GB restore limit.');
        }
    }
    foreach (['instance.json', 'application/.env', 'mysql/auto.cnf'] as $required) {
        if ($zip->locateName($required) === false) {
            throw new RuntimeException('Incomplete desktop backup.');
        }
    }
    if (! $zip->extractTo($input['target'])) {
        throw new RuntimeException('Cannot extract backup.');
    }
    $zip->close();
    echo json_encode(['ok' => true]);
    exit;
}
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($action === 'install') {
    foreach (['migrate' => ['--force' => true], 'db:seed' => ['--force' => true]] as $command => $args) {
        if (Artisan::call($command, $args) !== 0) {
            throw new RuntimeException('Database setup failed.');
        }
    }
    if (! User::role('super_admin')->exists()) {
        $validated = Validator::make($input, [
            'name' => 'required|string|max:100', 'email' => 'required|email', 'password' => 'required|string|min:12|max:200',
        ])->validate();
        $admin = User::create($validated);
        $admin->assignRole('super_admin');
        $admin->campuses()->syncWithoutDetaching(Campus::pluck('id'));
    }
    echo json_encode(['ok' => true]);
} elseif ($action === 'student-install') {
    if (Artisan::call('classroom:student-db', ['action' => 'install']) !== 0) {
        throw new RuntimeException('Student database setup failed: '.Artisan::output());
    }
    echo json_encode(['ok' => true]);
} elseif ($action === 'health') {
    DB::select('SELECT 1');
    app(StudentDbServer::class)->connect()->query('SELECT 1');
    echo json_encode(['ok' => true, 'users' => User::count(), 'studentDatabase' => true]);
} else {
    throw new RuntimeException('Unknown runtime action.');
}
