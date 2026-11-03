<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->folder = storage_path('framework/testing/campus-release-'.uniqid());
    File::ensureDirectoryExists($this->folder.'/.campus-releases');
    File::ensureDirectoryExists($this->folder.'/database');
    file_put_contents($this->folder.'/.env', 'original secret');
    file_put_contents($this->folder.'/database/campus.sqlite', 'existing results');
});

afterEach(fn () => File::deleteDirectory($this->folder));

function archiveForCampusUpdate(string $extra = '', bool $link = false): array
{
    $root = test()->folder;
    $sha = str_repeat('b', 40);
    $names = ['artisan', 'bootstrap/app.php', 'vendor/autoload.php', 'desktop-host/resources/server/host.cjs', 'scripts/run-campus-schedule.php'];
    if ($extra) {
        $names[] = $extra;
    }
    $zip = new ZipArchive;
    $zip->open($root.'/release.zip', ZipArchive::CREATE);
    $files = [];
    foreach ($names as $name) {
        $body = '<?php // fixture';
        $zip->addFromString($name, $body);
        $files[$name] = ['bytes' => strlen($body), 'sha256' => hash('sha256', $body)];
        if ($link && $name === $extra) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
    }
    $zip->addFromString('campus-release.json', json_encode(['sha' => $sha, 'protocol' => 1, 'backwardCompatible' => true, 'schemaSignature' => 'schema', 'files' => $files]));
    $zip->close();

    return [$root.'/release.zip', $root.'/.campus-releases/'.$sha, $sha, hash_file('sha256', $root.'/release.zip')];
}

it('extracts verified code into a new release without changing shared data or keys', function () {
    $arguments = archiveForCampusUpdate();
    $process = new Process([PHP_BINARY, base_path('scripts/campus-release.php'), 'extract', ...$arguments], base_path(), ['CAMPUS_SHARED_ROOT' => $this->folder]);
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(is_file($arguments[1].'/artisan'))->toBeTrue()
        ->and(file_get_contents($this->folder.'/.env'))->toBe('original secret')
        ->and(file_get_contents($this->folder.'/database/campus.sqlite'))->toBe('existing results');
});

it('rejects traversal shared data replacement and Windows path tricks before extraction', function (string $entry) {
    $arguments = archiveForCampusUpdate($entry);
    $process = new Process([PHP_BINARY, base_path('scripts/campus-release.php'), 'extract', ...$arguments], base_path(), ['CAMPUS_SHARED_ROOT' => $this->folder]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()->and(is_dir($arguments[1]))->toBeFalse()
        ->and(file_get_contents($this->folder.'/.env'))->toBe('original secret')
        ->and(file_get_contents($this->folder.'/database/campus.sqlite'))->toBe('existing results');
})->with(['../outside.php', '.env', 'storage/app/private/data', 'database/campus.sqlite', 'C:/outside.php', 'app\\outside.php', 'vendor/file:stream']);

it('rejects archive links and incorrect checksums', function () {
    $arguments = archiveForCampusUpdate('link', true);
    $process = new Process([PHP_BINARY, base_path('scripts/campus-release.php'), 'extract', ...$arguments], base_path(), ['CAMPUS_SHARED_ROOT' => $this->folder]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse();
    $arguments[3] = str_repeat('0', 64);
    $process = new Process([PHP_BINARY, base_path('scripts/campus-release.php'), 'extract', ...$arguments], base_path(), ['CAMPUS_SHARED_ROOT' => $this->folder]);
    $process->run();
    expect($process->isSuccessful())->toBeFalse()->and(is_dir($arguments[1]))->toBeFalse();
});
