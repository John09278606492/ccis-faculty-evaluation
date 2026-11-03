<?php

namespace App\Services\StudentDatabase;

use App\Models\QuizAnswer;
use App\Models\StudentDbAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PDO;
use PDOException;

class MySqlStudentDatabases implements StudentDatabases
{
    public function __construct(protected StudentDbServer $server) {}

    public function available(): bool
    {
        return $this->server->isInstalled() && $this->server->isRunning();
    }

    public function workspace(QuizAnswer $answer): array
    {
        return Cache::lock('student-db-answer-'.$answer->id, 300)->block(5, function () use ($answer) {
            $answer->refresh()->loadMissing('attempt.user', 'question');
            $account = $this->account($answer->attempt->user);
            $database = $answer->database_name ?? self::databaseName($answer);
            $this->validateName($database);
            $pdo = $this->server->connect();
            $writable = $answer->attempt->status === 'in_progress' && ! $answer->attempt->isExpired();
            $exists = (bool) $pdo->query('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($database))->fetchColumn();
            if (! $writable && ! $exists) {
                throw new \RuntimeException('This closed workspace is missing. Use the saved submission snapshot.');
            }
            if ($exists && $answer->database_name === null && $writable) {
                $pdo->exec("DROP DATABASE `{$database}`");
                $exists = false;
            }
            if (! $exists) {
                $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }
            $this->restrictLegacyGrants($pdo, $account->mysql_user);
            $this->grantWorkspace($pdo, $account->mysql_user, $database, $writable);
            if (! $exists && filled($starter = $answer->question->databaseStarterSql())) {
                $this->server->runClient('mysql', ["--database={$database}"], $account->mysql_user, $account->password, $starter);
            }
            if ($answer->database_name !== $database) {
                $answer->update(['database_name' => $database]);
            }

            return ['user' => $account->mysql_user, 'password' => $account->password, 'database' => $database];
        });
    }

    public function freeze(QuizAnswer $answer): void
    {
        if (! $answer->database_name) {
            return;
        }
        $answer->loadMissing('attempt.user');
        $this->validateName($answer->database_name);
        abort_unless(str_starts_with($answer->database_name, 'stu'.$answer->attempt->user_id.'_'), 422);
        $account = $this->account($answer->attempt->user);
        $pdo = $this->server->connect();
        $this->restrictLegacyGrants($pdo, $account->mysql_user);
        $this->grantWorkspace($pdo, $account->mysql_user, $answer->database_name, false);
        // Existing sessions can cache database privileges. Disconnect them so revoked
        // write access applies even in a phpMyAdmin tab opened before submission.
        $ids = $pdo->query('SELECT ID FROM information_schema.PROCESSLIST WHERE USER = '.$pdo->quote($account->mysql_user))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            try {
                $pdo->exec('KILL CONNECTION '.(int) $id);
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) !== 1094) {
                    throw $error;
                }
            }
        }
        $this->server->closeWorkspace($answer->database_name);
    }

    private function validateName(string $name): void
    {
        abort_unless(preg_match('/^stu\d+_[A-Za-z0-9_]+$/', $name) === 1, 422);
    }

    private function restrictLegacyGrants(PDO $pdo, string $user): void
    {
        abort_unless(preg_match('/^stu\d+$/', $user), 422);
        $this->revoke($pdo, "REVOKE ALL PRIVILEGES ON `{$user}\\_%`.* FROM '{$user}'@'%'");
    }

    private function revoke(PDO $pdo, string $statement): void
    {
        try {
            $pdo->exec($statement);
        } catch (PDOException $error) {
            // A fresh account has no legacy/database grant to revoke.
            if ((int) ($error->errorInfo[1] ?? 0) !== 1141) {
                throw $error;
            }
        }
    }

    private function grantWorkspace(PDO $pdo, string $user, string $database, bool $writable): void
    {
        $this->revoke($pdo, "REVOKE ALL PRIVILEGES ON `{$database}`.* FROM '{$user}'@'%'");
        $escaped = str_replace('_', '\\_', $database);
        $this->revoke($pdo, "REVOKE ALL PRIVILEGES ON `{$escaped}`.* FROM '{$user}'@'%'");
        $pdo->exec(self::workspaceGrant($user, $database, $writable));
    }

    public static function workspaceGrant(string $user, string $database, bool $writable): string
    {
        abort_unless(preg_match('/^stu\d+$/', $user) && preg_match('/^stu\d+_[A-Za-z0-9_]+$/', $database) && str_starts_with($database, $user.'_'), 422);
        $database = str_replace('_', '\\_', $database);

        return 'GRANT '.($writable ? 'ALL PRIVILEGES' : 'SELECT, SHOW VIEW')." ON `{$database}`.* TO '{$user}'@'%'";
    }

    public function export(QuizAnswer $answer): ?string
    {
        if (! $answer->database_name) {
            return null;
        }

        return $this->server->runClient('mysqldump', [
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--skip-dump-date',
            '--skip-comments',
            $answer->database_name,
        ], 'root', $this->server->rootPassword());
    }

    public function drop(string $database): void
    {
        abort_unless(preg_match('/^stu\d+_[A-Za-z0-9_]+$/', $database) === 1, 422);
        $this->server->connect()->exec("DROP DATABASE IF EXISTS `{$database}`");
    }

    /**
     * The student's MySQL account, created on first use. Its privileges cover
     * only databases named stu{id}_…, and it has connection and query limits.
     */
    public function account(User $user): StudentDbAccount
    {
        $account = StudentDbAccount::firstOrCreate(
            ['user_id' => $user->id],
            ['mysql_user' => "stu{$user->id}", 'password' => Str::password(24, symbols: false)],
        );
        $this->provisionAccount($account->mysql_user, $account->password);

        return $account;
    }

    /** Retry only idempotent account statements when MySQL drops a connection. */
    protected function provisionAccount(string $user, string $password): void
    {
        $pdo = $this->server->connect();
        foreach (self::accountStatements($user, $password, $pdo) as $statement) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $error) {
                if (! in_array((int) ($error->errorInfo[1] ?? 0), [2006, 2013], true)) {
                    throw $error;
                }
                // The server may have completed the statement before losing the
                // reply. CREATE IF NOT EXISTS, ALTER and GRANT can be repeated.
                $pdo = $this->server->connect();
                $pdo->exec($statement);
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function accountStatements(string $user, string $password, PDO $pdo): array
    {
        $quotedPassword = $pdo->quote($password);
        $limits = sprintf(
            'WITH MAX_USER_CONNECTIONS %d MAX_QUERIES_PER_HOUR %d',
            config('classroom.student_db.max_user_connections'),
            config('classroom.student_db.max_queries_per_hour'),
        );

        return [
            "CREATE USER IF NOT EXISTS '{$user}'@'%' IDENTIFIED BY {$quotedPassword} {$limits}",
            "ALTER USER '{$user}'@'%' IDENTIFIED BY {$quotedPassword} {$limits}",
        ];
    }

    public static function databaseName(QuizAnswer $answer): string
    {
        return "stu{$answer->attempt->user_id}_a{$answer->quiz_attempt_id}_q{$answer->quiz_question_id}";
    }
}
