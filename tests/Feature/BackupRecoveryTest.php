<?php

use App\Models\FormSubmission;
use App\Models\StudentDbAccount;
use App\Services\BackupRecovery;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->folder = storage_path('framework/testing/recovery-'.uniqid());
    File::ensureDirectoryExists($this->folder);
    config(['backup.path' => $this->folder.'/backups', 'backup.recovery_passphrase' => 'fixture recovery passphrase 2026']);
    DB::commit();
    // VACUUM needs a committed connection. Preserve the original in-memory test
    // baseline so fixtures created for the rehearsal never leak into later tests.
    if (DB::getDriverName() !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
        throw new LogicException('Restore rehearsals require the isolated in-memory test database.');
    }
    $this->baseline = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name != 'migrations'") as $table) {
        $this->baseline[$table->name] = DB::table($table->name)->get()->map(fn ($row) => (array) $row)->all();
    }
    $this->sequences = DB::table('sqlite_sequence')->get()->map(fn ($row) => (array) $row)->all();
});

afterEach(function () {
    DB::statement('PRAGMA foreign_keys = OFF');
    foreach ($this->baseline as $table => $rows) {
        DB::table($table)->delete();
    }
    foreach ($this->baseline as $table => $rows) {
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
    DB::table('sqlite_sequence')->delete();
    if ($this->sequences !== []) {
        DB::table('sqlite_sequence')->insert($this->sequences);
    }
    DB::statement('PRAGMA foreign_keys = ON');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    DB::beginTransaction();
    File::deleteDirectory($this->folder);
});

it('rehearses a verified restore with the original encryption key and protected SQL evidence in an isolated directory', function () {
    extract(recoveryAssessment());
    $account = StudentDbAccount::create(['user_id' => $student->id, 'mysql_user' => 'stu'.$student->id, 'password' => 'fixture-db-password']);
    $submission = FormSubmission::create(['form_id' => $attempt->quiz->courseOffering->form_id,
        'course_offering_id' => $attempt->quiz->course_offering_id, 'data' => [], 'evaluator_identity' => ['name' => 'Recovery Fixture Student']]);
    $sql = 'CREATE TABLE restore_proof (id INT);';
    $attempt->update(['status' => 'submitted']);
    $answer->update(['database_export' => $sql, 'database_snapshot_status' => 'ready', 'database_snapshot_hash' => hash('sha256', $sql), 'database_snapshot_at' => now()]);
    $this->artisan('classroom:backup')->assertSuccessful();
    $archive = glob($this->folder.'/backups/*.zip')[0];
    $manifest = app(BackupRecovery::class)->inspect($archive);
    expect($manifest['protected_key_included'])->toBeTrue()->and($manifest['workspaces'])->toHaveCount(1);
    $zip = new ZipArchive;
    $zip->open($archive);
    expect($zip->getFromName('recovery/application-key.enc.json'))->not->toContain(config('app.key'));
    $zip->close();
    app(BackupRecovery::class)->stage($archive, $this->folder.'/isolated-restored', 'fixture recovery passphrase 2026');
    $key = file_get_contents($this->folder.'/isolated-restored/recovery/application-key.txt');
    expect($key)->toBe(config('app.key'));
    $rawKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    $cipher = new Encrypter($rawKey, $manifest['application_cipher']);
    $restored = new PDO('sqlite:'.$this->folder.'/isolated-restored/database.sqlite');
    $encrypted = $restored->query('SELECT password FROM student_db_accounts WHERE id = '.(int) $account->id)->fetchColumn();
    expect($cipher->decryptString($encrypted))->toBe('fixture-db-password');
    $identity = $restored->query('SELECT evaluator_identity FROM form_submissions WHERE id = '.(int) $submission->id)->fetchColumn();
    expect(json_decode($cipher->decryptString($identity), true)['name'])->toBe('Recovery Fixture Student');
    expect(file_get_contents($this->folder.'/isolated-restored/'.$manifest['workspaces'][0]['entry']))->toBe($sql);
    expect(StudentDbAccount::find($account->id)->password)->toBe('fixture-db-password');
});

it('rejects a wrong recovery passphrase and archive tampering without writing a live database', function () {
    $this->artisan('classroom:backup')->assertSuccessful();
    $archive = glob($this->folder.'/backups/*.zip')[0];
    $zip = new ZipArchive;
    $zip->open($archive);
    $envelope = $zip->getFromName('recovery/application-key.enc.json');
    $manifest = app(BackupRecovery::class)->inspect($archive);
    expect(fn () => app(BackupRecovery::class)->recoverKey($envelope, 'wrong passphrase', $manifest['application_key_fingerprint']))->toThrow(RuntimeException::class);
    $zip->addFromString('database.sqlite', 'tampered database');
    $zip->close();
    expect(fn () => app(BackupRecovery::class)->stage($archive, $this->folder.'/must-not-exist'))->toThrow(RuntimeException::class);
    expect(file_exists($this->folder.'/must-not-exist'))->toBeFalse();
});

it('rejects unsafe ZIP paths and never overwrites an existing staging directory', function () {
    $archive = $this->folder.'/unsafe.zip';
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('../outside.txt', 'bad');
    $zip->addFromString('manifest.json', json_encode(['version' => 2, 'entries' => ['../outside.txt' => ['bytes' => 3, 'sha256' => hash('sha256', 'bad')]]]));
    $zip->close();
    expect(fn () => app(BackupRecovery::class)->inspect($archive))->toThrow(RuntimeException::class);
    $this->artisan('classroom:backup')->assertSuccessful();
    $valid = glob($this->folder.'/backups/*.zip')[0];
    expect(fn () => app(BackupRecovery::class)->stage($valid, $this->folder))->toThrow(RuntimeException::class);
    expect(file_exists(dirname($this->folder).'/outside.txt'))->toBeFalse();
});
