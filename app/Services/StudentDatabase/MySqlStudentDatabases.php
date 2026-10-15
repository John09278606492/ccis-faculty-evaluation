<?php

namespace App\Services\StudentDatabase;

use App\Models\QuizAnswer;
use App\Models\StudentDbAccount;
use App\Models\User;
use Illuminate\Support\Str;
use PDO;

class MySqlStudentDatabases implements StudentDatabases
{
    public function __construct(protected StudentDbServer $server) {}

    public function available(): bool
    {
        return $this->server->isInstalled() && $this->server->isRunning();
    }

    public function workspace(QuizAnswer $answer): array
    {
        $answer->loadMissing('attempt.user', 'question');
        $account = $this->account($answer->attempt->user);
        $database = $answer->database_name ?? self::databaseName($answer);
        $pdo = $this->server->connect();
        $exists = (bool) $pdo->query('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($database))->fetchColumn();
        // database_name is saved only once setup succeeded, so a database without it
        // is left over from a failed setup and is rebuilt.
        if ($exists && $answer->database_name === null) {
            $pdo->exec("DROP DATABASE `{$database}`");
            $exists = false;
        }
        if (! $exists) {
            $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            // Starter SQL runs in the student's own account, so it can only touch their databases.
            if (filled($starter = $answer->question->databaseStarterSql())) {
                $this->server->runClient('mysql', ["--database={$database}"], $account->mysql_user, $account->password, $starter);
            }
        }
        if ($answer->database_name !== $database) {
            $answer->update(['database_name' => $database]);
        }

        return ['user' => $account->mysql_user, 'password' => $account->password, 'database' => $database];
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
        $pdo = $this->server->connect();
        foreach (self::accountStatements($account->mysql_user, $account->password, $pdo) as $statement) {
            $pdo->exec($statement);
        }

        return $account;
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
            "GRANT ALL PRIVILEGES ON `{$user}\_%`.* TO '{$user}'@'%'",
        ];
    }

    public static function databaseName(QuizAnswer $answer): string
    {
        return "stu{$answer->attempt->user_id}_a{$answer->quiz_attempt_id}_q{$answer->quiz_question_id}";
    }
}
