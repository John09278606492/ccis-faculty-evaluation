<?php

namespace App\Services\StudentDatabase;

use App\Models\QuizAnswer;

/**
 * Student database workspaces: one MySQL account per student and one database
 * per code-question answer, on the separate student database server.
 */
interface StudentDatabases
{
    /**
     * Whether the student database server is installed and running.
     */
    public function available(): bool;

    /**
     * Create (once) the answer's database in its student's account, loaded with
     * the question's starter SQL.
     *
     * @return array{user: string, password: string, database: string}
     */
    public function workspace(QuizAnswer $answer): array;

    /**
     * SQL dump of the answer's database, or null if it has none.
     */
    public function export(QuizAnswer $answer): ?string;

    public function drop(string $database): void;
}
