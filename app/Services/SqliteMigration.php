<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/** Copies raw encrypted values and keys unchanged; never overwrites a database. */
class SqliteMigration
{
    public function copy(string $destination): array
    {
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $destination) || is_file($destination) || ! is_dir(dirname($destination))) {
            throw new RuntimeException('Choose a new absolute SQLite filename in an existing local directory.');
        }
        $sourceName = DB::getDefaultConnection();
        $source = DB::connection($sourceName);
        $pending = array_diff(array_keys(app('migrator')->getMigrationFiles([database_path('migrations')])), $source->table('migrations')->pluck('migration')->all());
        if ($pending) {
            throw new RuntimeException('Apply pending migrations to the source database before copying: '.implode(', ', $pending));
        }
        $schema = $source->getSchemaBuilder();
        $tables = array_column($schema->getTables(), 'name');
        sort($tables);
        $columns = [];
        foreach ($tables as $table) {
            if ($table === 'sqlite_sequence') {
                continue;
            }
            $columns[$table] = $schema->getColumns($table);
        }
        touch($destination);
        config(['database.connections.sqlite_copy' => [
            ...config('database.connections.sqlite'), 'url' => null, 'database' => $destination,
        ]]);
        $ownTransaction = $source->transactionLevel() === 0;
        try {
            DB::setDefaultConnection('sqlite_copy');
            Artisan::call('migrate', ['--database' => 'sqlite_copy', '--force' => true]);
            $target = DB::connection('sqlite_copy');
            $targetSchema = $target->getSchemaBuilder();
            foreach ($columns as $table => $definition) {
                $expected = array_column($definition, 'name');
                $actual = $targetSchema->getColumnListing($table);
                sort($expected);
                sort($actual);
                if ($actual !== $expected) {
                    throw new RuntimeException("Schema differs for {$table}; migration stopped without changing source data.");
                }
            }
            $target->statement('PRAGMA foreign_keys = OFF');
            if ($ownTransaction) {
                $source->beginTransaction();
            }
            $report = [];
            $target->transaction(function () use ($source, $target, $columns, &$report) {
                foreach (array_keys($columns) as $table) {
                    $target->table($table)->delete();
                }
                foreach ($columns as $table => $definition) {
                    $count = 0;
                    foreach ($source->table($table)->cursor() as $row) {
                        $target->table($table)->insert((array) $row);
                        $count++;
                    }
                    $before = $this->digest($source, $table, $definition);
                    $after = $this->digest($target, $table, $definition);
                    if ($before !== $after || $target->table($table)->count() !== $count) {
                        throw new RuntimeException("Verification failed for {$table}.");
                    }
                    $report[$table] = ['rows' => $count, 'sha256' => $after];
                }
            });
            if ($ownTransaction) {
                $source->commit();
            }
            $target->statement('PRAGMA foreign_keys = ON');
            if ($target->select('PRAGMA foreign_key_check') !== [] || $target->selectOne('PRAGMA integrity_check')->integrity_check !== 'ok') {
                throw new RuntimeException('SQLite integrity or foreign-key verification failed.');
            }
            $target->statement('PRAGMA wal_checkpoint(TRUNCATE)');
            $manifest = ['created_at' => now()->toIso8601String(), 'source_driver' => $source->getDriverName(), 'tables' => $report,
                'key_fingerprint' => hash('sha256', config('app.key')), 'destination' => $destination];
            file_put_contents($destination.'.verification.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $manifest;
        } catch (Throwable $error) {
            if ($ownTransaction && $source->transactionLevel() > 0) {
                $source->rollBack();
            }
            throw $error;
        } finally {
            DB::setDefaultConnection($sourceName);
            DB::purge('sqlite_copy');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function digest(Connection $connection, string $table, array $columns): string
    {
        $hashes = [];
        foreach ($connection->table($table)->cursor() as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row->{$column['name']};
                if ($value !== null) {
                    $type = strtolower($column['type_name']);
                    $value = preg_match('/int|bool/', $type) ? (string) (int) $value
                        : (preg_match('/decimal|numeric|float|double|real/', $type) ? (string) (float) $value : (string) $value);
                }
                $values[$column['name']] = $value;
            }
            ksort($values);
            $hashes[] = hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
        }
        sort($hashes);

        return hash('sha256', implode('', $hashes));
    }
}
