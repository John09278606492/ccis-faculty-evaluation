<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Private updater helper. Never exports keys, resets the database or runs migrations.
$mode = $argv[1] ?? '';
try {
    $shared = realpath(getenv('CAMPUS_SHARED_ROOT') ?: dirname(__DIR__));
    if (! $shared) {
        throw new RuntimeException('Shared campus directory is unavailable.');
    }
    if ($mode === 'extract') {
        [$archive, $target, $sha, $checksum] = array_slice($argv, 2);
        $parent = realpath(dirname($target));
        if (! preg_match('/^[a-f0-9]{40}$/', $sha) || ! preg_match('/^[a-f0-9]{64}$/', $checksum)
            || $parent !== realpath($shared.'/.campus-releases') || basename($target) !== $sha || file_exists($target)
            || ! hash_equals($checksum, hash_file('sha256', $archive))) {
            throw new RuntimeException('Invalid release archive or destination.');
        }
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true || $zip->numFiles > 50000) {
            throw new RuntimeException('Invalid release ZIP.');
        }
        $raw = $zip->getFromName('campus-release.json');
        if (! is_string($raw) || strlen($raw) > 12000000) {
            throw new RuntimeException('Release manifest is missing.');
        }
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (($manifest['sha'] ?? '') !== $sha || ($manifest['protocol'] ?? null) !== 1 || ($manifest['backwardCompatible'] ?? false) !== true) {
            throw new RuntimeException('Unsupported release protocol.');
        }
        $seen = [];
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            if (str_contains($name, '\\') || str_contains($name, ':') || str_contains($name, "\0") || str_starts_with($name, '/')
                || preg_match('~(^|/)\.\.?(/|$)|^(storage|runtime|output|work|\.git|\.campus-releases)/|(^|/)\.env($|\.(?!example$))|^bootstrap/cache/|^database/.*\.sqlite~i', $name)
                || isset($seen[strtolower($name)])) {
                throw new RuntimeException('Unsafe or duplicate release entry.');
            }
            $seen[strtolower($name)] = true;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                throw new RuntimeException('Release links are not permitted.');
            }
            $bytes += $stat['size'];
            if ($bytes > 700000000 || $stat['size'] > 200000000) {
                throw new RuntimeException('Release exceeds the extraction limit.');
            }
            if (str_ends_with($name, '/') || $name === 'campus-release.json') {
                continue;
            }
            $expected = $manifest['files'][$name] ?? null;
            if (! $expected || $expected['bytes'] !== $stat['size']) {
                throw new RuntimeException('Unexpected release entry.');
            }
            $stream = $zip->getStream($name);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            fclose($stream);
            if (! hash_equals($expected['sha256'], hash_final($hash))) {
                throw new RuntimeException('Release entry checksum failed.');
            }
        }
        foreach (array_keys($manifest['files']) as $name) {
            if (! isset($seen[strtolower($name)])) {
                throw new RuntimeException('Release files are missing.');
            }
        }
        foreach (['artisan', 'bootstrap/app.php', 'vendor/autoload.php', 'desktop-host/resources/server/host.cjs', 'scripts/run-campus-schedule.php'] as $required) {
            if (! isset($manifest['files'][$required])) {
                throw new RuntimeException('Required release code is missing.');
            }
        }
        mkdir($target, 0700);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $file = $target.'/'.rtrim($name, '/');
            if (str_ends_with($name, '/')) {
                if (! is_dir($file)) {
                    mkdir($file, 0700, true);
                }

continue;
            }
            if (! is_dir(dirname($file))) {
                mkdir(dirname($file), 0700, true);
            }
            $in = $zip->getStream($name);
            $out = fopen($file, 'xb');
            if (! $in || ! $out) {
                throw new RuntimeException('Cannot write the staged release.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
        }
        $zip->close();
        echo json_encode(['sha' => $sha, 'schemaSignature' => $manifest['schemaSignature']]);
        exit;
    }
    $project = realpath($argv[2] ?? '');
    if (! $project || ! is_file($project.'/vendor/autoload.php')) {
        throw new RuntimeException('Release code is unavailable.');
    }
    require $project.'/vendor/autoload.php';
    $app = require $project.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $actual = realpath(DB::connection()->getDatabaseName());
    if (config('database.default') !== 'sqlite' || $actual !== realpath($shared.'/database/campus.sqlite')) {
        throw new RuntimeException('The release must use the existing campus SQLite file.');
    }
    if ($mode === 'snapshot') {
        $target = $argv[3] ?? '';
        $allowed = realpath($shared.'/storage/app/campus-updates/backups');
        if (! $allowed || realpath(dirname($target)) !== $allowed || file_exists($target)) {
            throw new RuntimeException('Invalid snapshot destination.');
        }
        DB::statement('VACUUM INTO ?', [$target]);
        echo json_encode(['snapshot' => basename($target)]);
        exit;
    }
    if ($mode !== 'inspect') {
        throw new RuntimeException('Unsupported updater operation.');
    }
    $schema = [];
    foreach (File::allFiles($project.'/database/migrations') as $file) {
        $schema[str_replace('\\', '/', $file->getRelativePathname())] = hash('sha256', str_replace("\r\n", "\n", file_get_contents($file->getPathname())));
    }
    ksort($schema);
    $applied = DB::table('migrations')->pluck('migration')->all();
    $pending = array_filter(array_keys($schema), fn ($file) => ! in_array(pathinfo($file, PATHINFO_FILENAME), $applied, true));
    echo json_encode(['schemaSignature' => hash('sha256', json_encode($schema, JSON_UNESCAPED_SLASHES)),
        'pendingMigrations' => count($pending),
        'applicationKey' => hash('sha256', config('app.key')), 'evaluationKey' => hash('sha256', config('evaluations.fingerprint_key')),
        'database' => $actual, 'storage' => realpath(storage_path())], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, 'Release preparation failed: '.$error->getMessage().PHP_EOL);
    exit(1);
}
