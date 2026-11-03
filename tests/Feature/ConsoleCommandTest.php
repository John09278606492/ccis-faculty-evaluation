<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Role;

it('uses the shared environment file after a release restart without replacing existing keys', function () {
    $folder = storage_path('framework/testing/shared-live-keys-'.uniqid());
    File::ensureDirectoryExists($folder);
    $contents = "APP_KEY=keep-application-key\nEVALUATION_FINGERPRINT_KEY=keep-evaluation-key\nREVERB_APP_ID=keep-id\nREVERB_APP_KEY=keep-public-key\nREVERB_APP_SECRET=keep-private-secret\n";
    file_put_contents($folder.'/.env', $contents);
    $previous = $this->app->environmentPath();
    try {
        $this->app->useEnvironmentPath($folder);
        $this->artisan('classroom:live-keys')->assertSuccessful();
        expect(file_get_contents($folder.'/.env'))->toBe($contents);
    } finally {
        $this->app->useEnvironmentPath($previous);
        File::deleteDirectory($folder);
    }
});
/**
 * The commands the classroom server runs on its own.
 */
it('leaves the live update keys alone when they are already set', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=CCIS\nREVERB_APP_ID=ccis-classroom\nREVERB_APP_KEY=already-set\nREVERB_APP_SECRET=also-set\n");

    $this->artisan('classroom:live-keys', ['--env-file' => $env])->assertSuccessful();

    expect(file_get_contents($env))->toContain('REVERB_APP_KEY=already-set')
        ->and(file_get_contents($env))->toContain('REVERB_APP_SECRET=also-set');
    unlink($env);
});

it('adds the missing live update keys', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=CCIS\n");

    $this->artisan('classroom:live-keys', ['--env-file' => $env])->assertSuccessful();

    $contents = file_get_contents($env);
    expect($contents)->toMatch('/^REVERB_APP_ID=ccis-classroom$/m')
        ->toMatch('/^REVERB_APP_KEY=[a-z0-9]{20}$/m')
        ->toMatch('/^REVERB_APP_SECRET=[a-z0-9]{32}$/m');
    unlink($env);
});

it('fails when there is no env file to update', function () {
    $this->artisan('classroom:live-keys', ['--env-file' => sys_get_temp_dir().'/no-such-env-file-'.uniqid()])
        ->assertFailed();
});

it('leaves the install alone when a super admin already exists', function () {
    Role::findOrCreate('super_admin', 'web');
    User::factory()->create()->assignRole('super_admin');

    $this->artisan('classroom:install')
        ->expectsOutputToContain('A super admin already exists')
        ->assertSuccessful();
});

it('refuses an install with a bad email or a short password', function () {
    $this->artisan('classroom:install')
        ->expectsQuestion('Name', 'Val')
        ->expectsQuestion('Email', 'not-an-email')
        ->expectsQuestion('Password (at least 8 characters)', 'short')
        ->assertFailed();

    expect(User::where('email', 'not-an-email')->exists())->toBeFalse();
});

it('refuses an install with an email that is already taken', function () {
    User::factory()->create(['email' => 'taken@example.test']);

    $this->artisan('classroom:install')
        ->expectsQuestion('Name', 'Val')
        ->expectsQuestion('Email', 'taken@example.test')
        ->expectsQuestion('Password (at least 8 characters)', 'secret123')
        ->assertFailed();
});

it('gives the backup folder a single backup a day', function () {
    $folder = storage_path('framework/testing/backups-'.uniqid());
    config(['backup.path' => $folder, 'backup.keep_days' => 14]);

    // SQLite cannot VACUUM INTO inside RefreshDatabase's transaction.
    DB::commit();

    try {
        $this->artisan('classroom:backup --if-due')->assertSuccessful();
        $this->artisan('classroom:backup --if-due')->assertSuccessful();

        expect(glob($folder.'/ccis-backup-*.zip'))->toHaveCount(1);
    } finally {
        DB::beginTransaction();
        File::deleteDirectory($folder);
    }
});

it('keeps the uploaded files of both disks in the backup', function () {
    $folder = storage_path('framework/testing/backups-'.uniqid());
    config(['backup.path' => $folder, 'backup.keep_days' => 14]);
    $private = storage_path('app/private/backup-check.txt');
    $public = storage_path('app/public/backup-check.txt');
    File::ensureDirectoryExists(dirname($public));
    File::put($private, 'private file');
    File::put($public, 'public file');

    DB::commit();

    try {
        $this->artisan('classroom:backup')->assertSuccessful();

        $made = glob($folder.'/ccis-backup-'.now()->format('Y-m-d').'-*.zip');
        $zip = new ZipArchive;
        $zip->open($made[0]);
        expect($zip->locateName('files/private/backup-check.txt'))->not->toBeFalse()
            ->and($zip->locateName('files/public/backup-check.txt'))->not->toBeFalse();
        $zip->close();
    } finally {
        DB::beginTransaction();
        File::delete($private);
        File::delete($public);
        File::deleteDirectory($folder);
    }
});
