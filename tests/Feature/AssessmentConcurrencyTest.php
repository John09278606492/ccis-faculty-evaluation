<?php

use App\Support\SqliteWriter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A whole class starting, saving and submitting at once (see scripts/assessment-load-test.cjs).
 */
afterEach(function () {
    DB::purge('contention');
    DB::setDefaultConnection('sqlite');
    foreach (glob(storage_path('framework/testing/contention-*.sqlite*')) as $file) {
        @unlink($file);
    }
});

function classmateDatabase(): PDO
{
    $path = storage_path('framework/testing/contention-'.uniqid().'.sqlite');
    @mkdir(dirname($path), 0777, true);
    touch($path);
    config(['database.connections.contention' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false, 'busy_timeout' => 0, 'journal_mode' => 'wal']]);
    DB::setDefaultConnection('contention');
    DB::statement('CREATE TABLE quiz_attempts (id INTEGER PRIMARY KEY, n INTEGER)');
    DB::table('quiz_attempts')->insert(['id' => 1, 'n' => 0]);
    $classmate = new PDO('sqlite:'.$path);
    $classmate->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $classmate->setAttribute(PDO::ATTR_TIMEOUT, 0);

    return $classmate;
}

it('fails a read-then-write save at once when a classmate saved in between, without the writer reservation', function () {
    $classmate = classmateDatabase();

    expect(fn () => DB::transaction(function () use ($classmate) {
        DB::table('quiz_attempts')->where('id', 1)->value('n');
        $classmate->exec('UPDATE quiz_attempts SET n = 2');
        DB::table('quiz_attempts')->where('id', 1)->update(['n' => 3]);
    }))->toThrow(QueryException::class, 'locked');
});

it('makes classmates wait their turn once a save holds the writer reservation', function () {
    $classmate = classmateDatabase();

    DB::transaction(function () use ($classmate) {
        SqliteWriter::reserve('quiz_attempts', 1);
        DB::table('quiz_attempts')->where('id', 1)->value('n');
        // The classmate's write waits for this one (here it gives up at once to show it is blocked).
        expect(fn () => $classmate->exec('UPDATE quiz_attempts SET n = 2'))->toThrow(PDOException::class);
        DB::table('quiz_attempts')->where('id', 1)->update(['n' => 3]);
    });

    expect(DB::table('quiz_attempts')->where('id', 1)->value('n'))->toBe(3);
});
