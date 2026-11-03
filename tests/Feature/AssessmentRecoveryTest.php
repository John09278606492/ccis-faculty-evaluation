<?php

use App\Models\QuizAnswer;
use App\Models\StudentDbAccount;
use App\Models\User;
use App\Services\FinalizeAssessmentAttempt;
use App\Services\StudentDatabase\DatabaseSnapshots;
use App\Services\StudentDatabase\MySqlStudentDatabases;
use App\Services\StudentDatabase\StudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;

function recoveryDatabases(): StudentDatabases
{
    $fake = new class implements StudentDatabases
    {
        public bool $online = true;

        public bool $failExport = false;

        public array $frozen = [];

        public array $dropped = [];

        public int $exports = 0;

        public function available(): bool
        {
            return $this->online;
        }

        public function workspace(QuizAnswer $answer): array
        {
            return [];
        }

        public function freeze(QuizAnswer $answer): void
        {
            $this->frozen[] = $answer->id;
        }

        public function export(QuizAnswer $answer): ?string
        {
            $this->exports++;
            if ($this->failExport) {
                throw new RuntimeException('Simulated database outage');
            }

            return 'CREATE TABLE proof (id INT);';
        }

        public function drop(string $database): void
        {
            $this->dropped[] = $database;
        }
    };
    app()->instance(StudentDatabases::class, $fake);

    return $fake;
}

it('finalizes an expired attempt without an open browser and is idempotent', function () {
    extract(recoveryAssessment());
    $databases = recoveryDatabases();
    $this->artisan('classroom:assessments-maintain')->assertSuccessful();
    expect($attempt->refresh()->status)->toBe('submitted')->and($attempt->finalized_reason)->toBe('expired')
        ->and((float) $attempt->score)->toBe(5.0)->and($attempt->submitted_at->equalTo($attempt->expires_at))->toBeTrue();
    expect($answer->refresh()->hasVerifiedDatabaseSnapshot())->toBeTrue();
    app(FinalizeAssessmentAttempt::class)->handle($attempt);
    expect($databases->exports)->toBe(1)->and($attempt->refresh()->status)->toBe('submitted');
});

it('retains failed evidence and recovers a frozen snapshot without changing submitted answers', function () {
    extract(recoveryAssessment());
    $databases = recoveryDatabases();
    $databases->failExport = true;
    app(FinalizeAssessmentAttempt::class)->handle($attempt);
    expect($attempt->refresh()->status)->toBe('submitted')->and($answer->refresh()->database_snapshot_status)->toBe('failed')
        ->and($answer->database_name)->not->toBeNull()->and($answer->database_export)->toBeNull();
    $databases->failExport = false;
    $this->artisan('classroom:assessments-maintain')->assertSuccessful();
    expect($answer->refresh()->hasVerifiedDatabaseSnapshot())->toBeTrue()->and($answer->answer_text)->toBe('{}');
    expect($databases->frozen)->toContain($answer->id);
});

it('refuses to prune the only evidence and preserves immutable verified exports', function () {
    extract(recoveryAssessment());
    $databases = recoveryDatabases();
    $attempt->update(['status' => 'graded', 'submitted_at' => now()]);
    $this->artisan('classroom:student-db prune')->assertFailed();
    expect($databases->dropped)->toBe([]);
    app(DatabaseSnapshots::class)->capture($answer);
    $this->artisan('classroom:student-db prune')->assertSuccessful();
    expect($databases->dropped)->toHaveCount(1)->and($answer->refresh()->database_name)->toBeNull()->and($answer->hasVerifiedDatabaseSnapshot())->toBeTrue();
});

it('does not replace a snapshot after an integrity failure', function () {
    extract(recoveryAssessment());
    $databases = recoveryDatabases();
    app(FinalizeAssessmentAttempt::class)->handle($attempt);
    $original = $answer->refresh()->database_export;
    $answer->update(['database_export' => $original.' tampered']);
    expect(app(DatabaseSnapshots::class)->capture($answer))->toBeFalse();
    expect($databases->exports)->toBe(1)->and($answer->refresh()->database_export)->toBe($original.' tampered')
        ->and($answer->database_snapshot_status)->toBe('failed');
});

it('revokes broad grants and disconnects an existing mysql session before read-only access', function () {
    extract(recoveryAssessment());
    $attempt->update(['status' => 'submitted']);
    $sql = [];
    $statement = Mockery::mock(PDOStatement::class);
    $statement->shouldReceive('fetchAll')->with(PDO::FETCH_COLUMN)->once()->andReturn([42]);
    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('exec')->andReturnUsing(function ($query) use (&$sql) {
        $sql[] = $query;

        return 0;
    });
    $pdo->shouldReceive('quote')->with('stu'.$student->id)->once()->andReturn("'stu{$student->id}'");
    $pdo->shouldReceive('query')->once()->andReturn($statement);
    $server = Mockery::mock(StudentDbServer::class);
    $server->shouldReceive('connect')->once()->andReturn($pdo);
    $server->shouldReceive('closeWorkspace')->with($answer->database_name)->once();
    $service = new class($server) extends MySqlStudentDatabases
    {
        public function account(User $user): StudentDbAccount
        {
            return new StudentDbAccount(['user_id' => $user->id, 'mysql_user' => 'stu'.$user->id, 'password' => 'fixture']);
        }
    };
    $service->freeze($answer);
    $joined = implode('; ', $sql);
    expect($joined)->toContain('REVOKE ALL PRIVILEGES')->toContain('GRANT SELECT, SHOW VIEW')
        ->toContain('KILL CONNECTION 42')->not->toContain('GRANT ALL PRIVILEGES');
    expect(MySqlStudentDatabases::workspaceGrant('stu12', 'stu12_a3_q4', false))
        ->toContain('`stu12\\_a3\\_q4`');
});
