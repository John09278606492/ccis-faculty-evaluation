<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\EvaluationCycle;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AssignedDataAccess;
use App\Services\EvaluationCycles;
use App\Services\EvaluationResultsRelease;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\ReportService;
use App\Services\StudentEvaluationProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EvaluationLoadAudit extends Command
{
    protected $signature = 'classroom:evaluation-load-audit {manifest} {--close}';

    protected $description = 'Verify isolated load-test submissions, privacy, scopes, scores, closure and release';

    public function handle(): int
    {
        abort_unless(app()->environment('loadtesting') && DB::getDriverName() === 'sqlite'
            && str_contains(str_replace('\\', '/', DB::connection()->getDatabaseName()), '/storage/app/load-tests/'), 403);
        $manifest = json_decode(file_get_contents($this->argument('manifest')), true, flags: JSON_THROW_ON_ERROR);
        $checks = [];
        $check = function (string $label, bool $passed, mixed $actual = null) use (&$checks) {
            $checks[$label] = ['passed' => $passed, 'actual' => $actual];
        };
        $campus = Campus::findOrFail($manifest['campus']);
        $admin = User::findOrFail(collect($manifest['staff'])->firstWhere('role', 'campus_admin')['id']);
        auth()->login($admin);
        $set = FormSubmission::whereHas('form', fn ($q) => $q->where('settings->annex', 'A'))->count();
        $sef = FormSubmission::whereHas('form', fn ($q) => $q->where('settings->annex', 'B'))->count();
        $check('expected_student_submissions', $set === $manifest['expected_student_submissions'], $set);
        $check('expected_supervisor_submissions', $sef === count($manifest['supervisor_assignments']) * 2, $sef);
        $check('no_duplicate_fingerprints', DB::table('form_submissions')->select('submission_fingerprint')->groupBy('submission_fingerprint')->havingRaw('count(*) > 1')->get()->isEmpty());
        $check('no_plaintext_student_identity', ! DB::table('form_submissions')->whereNotNull('user_id')->exists()
            && ! DB::table('form_submissions')->where('evaluator_identity', 'like', '%Student%')->exists());
        $check('encrypted_identity_decrypts', FormSubmission::first()?->evaluator_identity !== null);
        $incomplete = 0;
        foreach ($manifest['students'] as $student) {
            $user = User::findOrFail($student['id']);
            foreach ($student['assignments'] as $id) {
                $offering = CourseOffering::findOrFail($id);
                $hash = app(EvaluationSubmissionIdentity::class)->fingerprint($offering, request(), $user);
                if (! FormSubmission::where('course_offering_id', $id)->where('submission_fingerprint', $hash)->exists()) {
                    $incomplete++;
                }
            }
        }
        $check('every_student_assignment_recorded_once', $incomplete === 0, ['missing' => $incomplete]);
        foreach ($manifest['staff'] as $staff) {
            $user = User::findOrFail($staff['id']);
            if (in_array($staff['role'], ['dean', 'chairperson'], true)) {
                $ids = app(AssignedDataAccess::class)->departmentIds($user, $campus);
                $check($staff['role'].'_'.$user->id.'_department_scope', $ids === [$staff['department']], $ids);
            }
        }
        $first = User::findOrFail($manifest['students'][0]['id']);
        $progress = app(StudentEvaluationProgress::class)->summary($first, $campus, $manifest['period']);
        $check('student_progress_complete', $progress['completed'] === 2 && $progress['remaining'] === 0, $progress);
        $releaseBefore = [];
        $ratings = [];
        foreach (collect($manifest['staff'])->where('role', 'instructor') as $staff) {
            $faculty = User::findOrFail($staff['id']);
            $summary = app(ReportService::class)->getEvaluateeSummary($faculty, $manifest['period'], $campus->id);
            $ratings[$faculty->id] = ['SET' => $summary['instruments']['SET']['percentage'] ?? null, 'SEF' => $summary['instruments']['SEF']['percentage'] ?? null, 'completion' => $summary['completion_rate']];
            $check('faculty_'.$faculty->id.'_SET_SEF_scores', abs(($ratings[$faculty->id]['SET'] ?? 0) - 80) < .001 && abs(($ratings[$faculty->id]['SEF'] ?? 0) - 100) < .001 && $summary['completion_rate'] === 100.0, $ratings[$faculty->id]);
            foreach (CourseOffering::where('user_id', $faculty->id)->get() as $offering) {
                $releaseBefore[] = EvaluationResultsRelease::isReleased($offering);
            }
        }
        $check('faculty_scores_hidden_while_open', ! in_array(true, $releaseBefore, true));
        if ($this->option('close')) {
            foreach (EvaluationCycle::whereIn('id', $manifest['cycles'])->get() as $cycle) {
                app(EvaluationCycles::class)->close($admin, $cycle);
            }
            $released = CourseOffering::whereIn('evaluation_cycle_id', $manifest['cycles'])->get()->every(fn ($offering) => EvaluationResultsRelease::isReleased($offering));
            $check('faculty_scores_released_after_closure', $released);
            foreach (EvaluationCycle::whereIn('id', $manifest['cycles'])->get() as $cycle) {
                app(EvaluationCycles::class)->close($admin, $cycle, true);
            }
            $check('cycles_archived', EvaluationCycle::whereIn('id', $manifest['cycles'])->where('status', 'archived')->count() === count($manifest['cycles']));
        }
        $check('sqlite_integrity', DB::selectOne('PRAGMA integrity_check')->integrity_check === 'ok' && DB::select('PRAGMA foreign_key_check') === []);
        $failed = array_keys(array_filter($checks, fn ($row) => ! $row['passed']));
        $report = ['checked_at' => now()->toIso8601String(), 'passed' => $failed === [], 'checks' => $checks, 'failed' => $failed, 'ratings' => $ratings,
            'sqlite_version' => DB::selectOne('select sqlite_version() as version')->version, 'database_bytes' => filesize(DB::connection()->getDatabaseName())];
        file_put_contents(dirname($this->argument('manifest')).'/data-audit.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->line(count($checks).' checks; '.count($failed).' failed: '.implode(', ', $failed));

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
