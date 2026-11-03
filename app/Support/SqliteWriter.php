<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * lockForUpdate() does nothing on SQLite, and PHP 8.3's PDO cannot BEGIN IMMEDIATE.
 * A transaction that reads before it writes then fails at once with "database is
 * locked" when a classmate is writing, instead of waiting its turn. Writing first
 * takes SQLite's writer reservation, so the busy timeout applies and requests queue.
 */
class SqliteWriter
{
    public static function reserve(string $table, int $id): void
    {
        if (DB::transactionLevel() > 0 && DB::getDriverName() === 'sqlite') {
            DB::table($table)->where('id', $id)->update(['id' => $id]);
        }
    }
}
