<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->folder = storage_path('framework/testing/backups-'.uniqid());
    config(['backup.path' => $this->folder, 'backup.keep_days' => 14]);

    // SQLite cannot VACUUM INTO inside RefreshDatabase's transaction; the in-memory
    // database is rebuilt for every test, so committing here leaks nothing.
    DB::commit();
});

afterEach(function () {
    DB::beginTransaction();
    File::deleteDirectory($this->folder);
});

it('zips the database and uploaded files and removes backups older than the retention window', function () {
    File::ensureDirectoryExists($this->folder);
    touch($old = $this->folder.'/ccis-backup-2020-01-01-000000.zip', now()->subDays(20)->getTimestamp());
    touch($recent = $this->folder.'/ccis-backup-2020-01-02-000000.zip', now()->subDays(3)->getTimestamp());
    File::put($upload = storage_path('app/private/backup-test.txt'), 'workbook');

    try {
        $this->artisan('classroom:backup')->assertSuccessful();
    } finally {
        File::delete($upload);
    }

    $made = glob($this->folder.'/ccis-backup-'.now()->format('Y-m-d').'-*.zip');
    expect($made)->toHaveCount(1)
        ->and(file_exists($old))->toBeFalse()
        ->and(file_exists($recent))->toBeTrue();

    $zip = new ZipArchive;
    $zip->open($made[0]);
    expect($zip->locateName('database.sqlite'))->not->toBeFalse()
        ->and($zip->getFromName('files/private/backup-test.txt'))->toBe('workbook');
    $zip->close();
});

it('makes only one backup a day when run by the scheduler', function () {
    $this->artisan('classroom:backup --if-due')->assertSuccessful();
    $this->artisan('classroom:backup --if-due')->assertSuccessful();

    expect(glob($this->folder.'/ccis-backup-*.zip'))->toHaveCount(1);
});
