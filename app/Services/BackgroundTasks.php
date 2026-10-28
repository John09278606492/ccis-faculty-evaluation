<?php

namespace App\Services;

use App\Jobs\RunBackgroundTask;
use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\User;
use App\Notifications\BellNotification;
use Filament\Facades\Filament;
use Filament\Notifications\Actions\Action;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class BackgroundTasks
{
    public const KINDS = ['student-review', 'student-import', 'course-review', 'course-import', 'offering-review', 'offering-import', 'evaluator-sync', 'sectioning-import', 'supervisor-bulk', 'supervisor-resolution', 'evaluation-prepare', 'evaluation-append', 'roster-review', 'roster-import', 'section-review', 'section-import', 'staff-export', 'faculty-export', 'department-export', 'class-followup-export', 'evaluator-file', 'assessment-pins', 'assessment-export', 'workbook-upload'];

    private function scope(User $actor, Campus $campus): string
    {
        $ids = app(AssignedDataAccess::class)->departmentIds($actor, $campus);
        if (is_array($ids)) {
            sort($ids);
        }

        return hash('sha256', json_encode($ids));
    }

    public function authorize(User $actor, Campus $campus, string $kind): void
    {
        abort_unless(in_array($kind, self::KINDS, true) && ! $actor->isStudent() && $actor->canAccessTenant($campus), 403);
        match ($kind) {
            'student-review', 'student-import', 'course-review', 'course-import' => app(AcademicStudentImports::class)->authorize($actor, $campus),
            'staff-export' => abort_unless($actor->can('view_any_user'), 403),
            'class-followup-export' => abort_unless($actor->can('view_any_course::offering'), 403),
            'faculty-export' => abort_unless($actor->can('page_EvaluationResults'), 403),
            'department-export' => abort_unless($actor->isSuperAdmin() || $actor->can('view_any_department'), 403),
            'workbook-upload' => abort_unless($actor->can('page_ClassRecords'), 403),
            'section-review', 'section-import' => app(AcademicSections::class)->authorize($actor, $campus, true, true),
            'offering-review', 'offering-import' => app(InstructorOfferingImports::class)->authorize($actor, $campus),
            'evaluator-sync' => abort_unless(app(AutomaticEvaluatorAssignments::class)->allowed($actor, $campus), 403),
            'sectioning-import' => abort_unless(app(FacultySectioning::class)->allowed($actor, $campus), 403),
            'supervisor-bulk', 'supervisor-resolution' => abort_unless(app(DepartmentSupervisorAssignments::class)->allowed($actor, $campus), 403),
            'evaluation-prepare', 'evaluation-append' => abort_unless(app(EvaluationCycles::class)->allowed($actor, $campus), 403),
            'evaluator-file', 'roster-review', 'roster-import', 'assessment-pins', 'assessment-export' => null,
        };
        if (str_starts_with($kind, 'course-')) {
            abort_unless($actor->can('create_course'), 403);
        }
    }

    public function enqueue(User $actor, Campus $campus, string $kind, string $title, array $payload, ?string $dedupe = null): BackgroundTask
    {
        $this->authorize($actor, $campus, $kind);
        if (str_starts_with($kind, 'roster-')) {
            app(TeachingClassRosterImports::class)->offering($actor, $campus, $payload['offering_id']);
        }
        if (str_starts_with($kind, 'section-')) {
            app(SectionRosterImports::class)->section($actor, $campus, $payload['section_id']);
        }
        if (str_starts_with($kind, 'assessment-')) {
            app(AssessmentBulkFiles::class)->quiz($actor, $campus, $payload['quiz_id'], $kind === 'assessment-pins');
        }
        $key = $dedupe ? hash('sha256', $actor->id.'|'.$campus->id.'|'.$kind.'|'.$dedupe) : null;
        $task = DB::transaction(function () use ($actor, $campus, $kind, $title, $payload, $key) {
            $attributes = ['user_id' => $actor->id, 'campus_id' => $campus->id, 'kind' => $kind,
                'title' => $title, 'status' => 'queued', 'payload' => [...$payload, '_scope' => $this->scope($actor, $campus)]];
            $task = $key ? BackgroundTask::firstOrCreate(['dedupe_key' => $key], ['id' => (string) Str::uuid(), ...$attributes])
                : BackgroundTask::create(['id' => (string) Str::uuid(), ...$attributes]);
            if ($task->wasRecentlyCreated) {
                $this->notify($task, 'Background processing queued', $title.'. You can continue using the system; we will notify you when it is ready.');
                RunBackgroundTask::dispatch($task->id);
            }

            return $task;
        });

        return $task;
    }

    public function upload(User $actor, Campus $campus, UploadedFile $file, string $kind, string $title, array $payload): BackgroundTask
    {
        $this->authorize($actor, $campus, $kind);
        $maximum = ($kind === 'workbook-upload' ? 20 : 5) * 1024 * 1024;
        abort_unless($file->getSize() <= $maximum && in_array(strtolower($file->getClientOriginalExtension()), $kind === 'workbook-upload' ? ['xlsx', 'xls'] : ['csv', 'xlsx', 'xls'], true), 422, 'The file type or size is not allowed.');
        $path = 'background-tasks/'.Str::uuid().'/source.'.strtolower($file->getClientOriginalExtension());
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            abort_unless(Storage::disk('local')->put($path, $stream), 500);

            return $this->enqueue($actor, $campus, $kind, $title, [...$payload, 'source' => $path, 'source_name' => basename($file->getClientOriginalName())]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function confirm(BackgroundTask $review, User $actor, Campus $campus): BackgroundTask
    {
        $this->authorizeTask($review, $actor, $campus);
        abort_unless($review->status === 'completed' && in_array($review->kind, ['student-review', 'course-review', 'roster-review', 'section-review', 'offering-review'], true), 422);
        abort_unless($review->completed_at->gt(now()->subDays(7)), 410, 'This review expired. Upload and review the data again.');
        abort_if(($review->result['review']['error_count'] ?? 0) > 0, 422, 'Correct the validation errors and upload the file again. No rows have been imported.');
        $kind = match ($review->kind) {
            'student-review' => 'student-import', 'roster-review' => 'roster-import', 'section-review' => 'section-import', 'offering-review' => 'offering-import', default => 'course-import'
        };

        $task = $this->enqueue($actor, $campus, $kind, match ($kind) {
            'student-import' => 'Import reviewed students', 'roster-import' => 'Assign reviewed section roster', 'section-import' => 'Add reviewed students to section', 'offering-import' => 'Import ready teaching classes', default => 'Create reviewed courses'
        },
            ['plan' => $review->payload['review_plan'], 'summary' => $review->result['review'], ...($kind === 'roster-import' ? ['offering_id' => $review->payload['offering_id']] : []), ...($kind === 'section-import' ? ['section_id' => $review->payload['section_id']] : [])], $review->id);
        $review->update(['result->confirmed_task_id' => $task->id]);

        return $task;
    }

    public function authorizeTask(BackgroundTask $task, User $actor, Campus $campus): void
    {
        abort_unless($task->user_id === $actor->id && $task->campus_id === $campus->id, 403);
        $this->authorize($actor, $campus, $task->kind);
        abort_unless(hash_equals($task->payload['_scope'], $this->scope($actor, $campus)), 403, 'Your department assignments changed. Start this operation again with your current access.');
        if ($task->kind === 'evaluator-file') {
            app(EvaluatorFileImport::class)->offering($actor, $campus, $task->payload['offering_id']);
        }
        if (str_starts_with($task->kind, 'roster-')) {
            app(TeachingClassRosterImports::class)->offering($actor, $campus, $task->payload['offering_id']);
        }
        if (str_starts_with($task->kind, 'section-')) {
            app(SectionRosterImports::class)->section($actor, $campus, $task->payload['section_id']);
        }
        if (str_starts_with($task->kind, 'assessment-')) {
            app(AssessmentBulkFiles::class)->quiz($actor, $campus, $task->payload['quiz_id'], $task->kind === 'assessment-pins');
        }
    }

    public function inContext(User $actor, Campus $campus, callable $work): mixed
    {
        $previousActor = Auth::user();
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        $access = app(AssignedDataAccess::class);
        $previousScope = $access->actor;
        try {
            Auth::setUser($actor);
            Filament::setCurrentPanel(Filament::getPanel('admin'));
            Filament::setTenant($campus, isQuiet: true);
            $access->actor = $actor;

            return $work();
        } finally {
            $previousActor ? Auth::setUser($previousActor) : Auth::guard()->forgetUser();
            Filament::setCurrentPanel($previousPanel);
            Filament::setTenant($previousTenant, isQuiet: true);
            $access->actor = $previousScope;
        }
    }

    public function run(string $id): void
    {
        if (! BackgroundTask::whereKey($id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now()])) {
            return;
        }
        $task = BackgroundTask::findOrFail($id);
        ClassroomUpdates::panel('tasks', true);
        ClassroomUpdates::sendPanel();
        try {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($task->user_id);
            $campus = Campus::findOrFail($task->campus_id);
            $this->inContext($actor, $campus, function () use ($task, $actor, $campus) {
                $this->authorizeTask($task, $actor, $campus);
                $result = $this->perform($task, $actor, $campus);
                $task->update(['status' => 'completed', 'result' => $result, 'completed_at' => now()]);
                $needsCorrection = ($result['validation_error_count'] ?? 0) > 0;
                $this->notify($task, $needsCorrection ? 'Section list needs correction' : (str_ends_with($task->kind, '-review') ? 'Background review ready' : 'Background processing completed'), $result['message'], $needsCorrection);
            });
        } catch (Throwable $e) {
            $this->fail($id, $e);
        } finally {
            if ($source = $task->payload['source'] ?? null) {
                Storage::disk('local')->delete($source);
            }
            // A worker stays alive between jobs; request-termination callbacks alone
            // would leave tables/cards stale until the worker eventually stopped.
            ClassroomUpdates::sendPanel();
        }
    }

    private function perform(BackgroundTask $task, User $actor, Campus $campus): array
    {
        $data = $task->payload;
        if ($task->kind === 'evaluation-append') {
            return app(EvaluationClassAdditions::class)->apply($actor, $campus, $data);
        }
        if ($task->kind === 'evaluation-prepare') {
            return app(EvaluationDraftPreparation::class)->prepare($actor, $campus, (int) $data['semester_id'], $data['department_ids'] ?? []);
        }
        if ($task->kind === 'supervisor-resolution') {
            return app(SupervisorAssignmentGaps::class)->apply($actor, $campus, $data);
        }
        if ($task->kind === 'supervisor-bulk') {
            return app(DepartmentSupervisorAssignments::class)->apply($actor, $campus, $data);
        }
        if ($task->kind === 'sectioning-import') {
            $result = isset($data['source']) ? app(FacultySectioning::class)->importFile($actor, $campus, $data, $data['source'])
                : app(FacultySectioning::class)->assign($actor, $campus, $data, $data['student_ids'] ?? []);
            if ($result['issues'] ?? []) {
                $this->csv($task, 'section-assignment-validation.csv', function ($handle) use ($result) {
                    $this->row($handle, ['Row', 'Student ID', 'Section', 'Validation']);
                    foreach ($result['issues'] as $row) {
                        $this->row($handle, [$row['row'], $row['student_id'], $row['section'], implode(' ', $row['messages'])]);
                    }
                });
                $result['issues'] = array_slice($result['issues'], 0, 100);
            }

            return $result;
        }
        if ($task->kind === 'offering-review') {
            $prepared = app(InstructorOfferingImports::class)->prepare($actor, $campus, $data, $data['source']);
            $task->update(['payload' => [...$data, 'review_plan' => $prepared['plan']]]);
            $review = $prepared['review'];
            $this->csv($task, 'instructor-offerings-review.csv', function ($handle) use ($review) {
                $this->row($handle, ['Source rows', 'Subject', 'Faculty', 'Source Faculty ID', 'Teaching department', 'Section', 'Saved students', 'Status', 'Notes']);
                foreach ($review['details'] as $row) {
                    $this->row($handle, [$row['rows'], $row['subject'], $row['faculty'], $row['source_id'], $row['department'], $row['section'], $row['students'], $row['status'], implode(' ', $row['messages'])]);
                }
                foreach ($review['unclassified_period_rows'] ?? [] as $row) {
                    $this->row($handle, [($row['_sheet'] ?? '').':'.$row['_row'], $row['course_code'].' - '.$row['course_desc'], $row['last_name'].', '.$row['first_name'],
                        $row['id'], '', $row['section'], 0, 'Excluded: period unreadable', 'Missing or invalid school year/semester. '.implode(' ', $row['_read_errors'] ?? [])]);
                }
            });
            $review['unclassified_period_count'] = count($review['unclassified_period_rows'] ?? []);
            unset($review['unclassified_period_rows']);
            $review['details'] = array_slice($review['details'], 0, 100);

            return ['review' => $review, 'message' => $review['ready_classes'].' classes ready; '.$review['blocked_classes'].' need attention. Review before importing.'];
        }
        if ($task->kind === 'offering-import') {
            return app(InstructorOfferingImports::class)->commit($actor, $campus, $data['plan']);
        }
        if (in_array($task->kind, ['roster-review', 'section-review'], true)) {
            $prepared = $task->kind === 'section-review'
                ? app(SectionRosterImports::class)->prepare($actor, $campus, $data['section_id'], $data['source'])
                : app(TeachingClassRosterImports::class)->prepare($actor, $campus, $data['offering_id'], $data['source']);
            $task->update(['payload' => [...$data, 'review_plan' => $prepared['plan']]]);
            $review = $prepared['review'];
            $this->csv($task, 'section-roster-validation.csv', function ($handle) use ($review) {
                $this->row($handle, ['Row', 'Section', 'ID No.', 'Lastname', 'Firstname', 'Middlename', 'Sex', 'Validation', 'Notes']);
                foreach ($review['details'] as $row) {
                    $this->row($handle, [$row['row'], $row['section'], $row['student_id'], $row['last_name'], $row['first_name'], $row['middle_name'], $row['sex'],
                        $row['messages'] ? implode(' ', $row['messages']) : 'Matched', implode(' ', $row['warnings'])]);
                }
            });
            // Keep table/modal responses compact; the download retains every row.
            $review['details'] = array_slice($review['details'], 0, 100);

            return ['review' => $review, 'message' => $review['matched_students'].' students matched; '.$review['error_count'].' rows need correction. Review the student list before confirming. No assignments or section memberships have been changed.'];
        }
        if ($task->kind === 'section-import') {
            return app(SectionRosterImports::class)->commit($actor, $campus, $data['plan']);
        }
        if ($task->kind === 'roster-import') {
            return app(TeachingClassRosterImports::class)->commit($actor, $campus, $data['plan']);
        }
        if ($task->kind === 'student-review') {
            $review = app(AcademicStudentImports::class)->reviewUpload($actor, $campus, $data['source'], $data['semester_id'], null, $data['department_codes'] ?? []);
            $plan = app(AcademicStudentImports::class)->freezeReview($actor, $campus, $review['token'], $review['semester_id']);
            $task->update(['payload' => [...$data, 'review_plan' => $plan]]);
            $this->errorCsv($task, $review['errors'] ?? []);

            return ['review' => $review, 'message' => 'Student validation finished. Review the proposed students, departments and courses before confirming. No student records were changed.'];
        }
        if ($task->kind === 'student-import') {
            $count = app(AcademicStudentImports::class)->commitFrozen($actor, $campus, $data['plan']);
            $matching = app(AutomaticEvaluatorAssignments::class)->synchronize($actor, $campus, (int) $data['plan']['semester_id']);

            return ['row_count' => $count, 'automatic_evaluators' => $matching['assigned'], 'unresolved_evaluators' => $matching['unresolved'],
                'message' => $count.' student rows imported. '.($data['summary']['new_departments'] ?? 0).' departments and '.($data['summary']['new_courses'] ?? 0).' courses added. '
                    .$matching['assigned'].' evaluators matched automatically; '.$matching['unresolved'].' subject matches need a teacher/section choice.'];
        }
        if ($task->kind === 'evaluator-sync') {
            $matching = app(AutomaticEvaluatorAssignments::class)->synchronize($actor, $campus, (int) $data['semester_id']);

            return [...$matching, 'message' => $matching['assigned'].' evaluator assignments created automatically across '.$matching['classes'].' classes. '
                .$matching['unresolved'].' enrolled subject matches need a teacher/section choice. Existing confirmed rosters and submitted evaluations are preserved.'];
        }
        if ($task->kind === 'course-review') {
            $review = app(ImportedCourseCatalogue::class)->previewStored($actor, $campus, $data['semester_id'], $data['department_codes']);
            $plan = app(ImportedCourseCatalogue::class)->storedReview($actor, $campus, $review['token']);
            $task->update(['payload' => [...$data, 'review_plan' => $plan]]);

            return ['review' => $review, 'message' => 'Course review ready. Confirm the proposed courses before they are created.'];
        }
        if ($task->kind === 'course-import') {
            $result = app(ImportedCourseCatalogue::class)->commitFrozen($actor, $campus, $data['plan']);

            return ['message' => $result['new_courses'].' courses created; '.$result['existing_courses'].' existing matches retained.'];
        }
        if ($task->kind === 'evaluator-file') {
            return app(EvaluatorFileImport::class)->run($actor, $campus, $data['offering_id'], $data['source']);
        }
        if (str_starts_with($task->kind, 'assessment-')) {
            return app(AssessmentBulkFiles::class)->generate($task, $actor, $campus);
        }
        if ($task->kind === 'workbook-upload') {
            return app(BackgroundWorkbookUpload::class)->run($task, $actor);
        }

        return app(BackgroundExports::class)->generate($task, $actor, $campus);
    }

    public function fail(string $id, ?Throwable $exception): void
    {
        $task = BackgroundTask::find($id);
        if (! $task || in_array($task->status, ['completed', 'failed'], true)) {
            return;
        }
        $message = $exception instanceof ValidationException ? implode(' ', array_slice($exception->validator->errors()->all(), 0, 5))
            : ($exception instanceof HttpException && $exception->getStatusCode() < 500 ? 'The operation could not continue with your current access or selected records. Review the selection and try again.'
                : 'The background operation did not finish. Share task reference '.$id.' with your campus administrator.');
        $task->update(['status' => 'failed', 'error' => $message, 'completed_at' => now()]);
        if ($exception) {
            Log::error('Background task failed', ['task_id' => $id, 'exception' => $exception]);
        }
        $this->notify($task, 'Background processing failed', $message, true);
    }

    private function notify(BackgroundTask $task, string $title, string $message, bool $failed = false): void
    {
        $actor = User::withoutGlobalScope('assigned_data')->find($task->user_id);
        if (! $actor) {
            return;
        }
        $notification = BellNotification::make()->title($title)->body($message)
            ->actions([Action::make('view')->label('View progress / download')->url('/'.$task->campus_id.'/background-tasks?tableSearch='.$task->id)->markAsRead()]);
        $failed ? $notification->danger() : $notification->success();
        $notification->sendToDatabase($actor);
    }

    private function errorCsv(BackgroundTask $task, array $errors): void
    {
        if (! $errors) {
            return;
        }
        $this->csv($task, 'student-upload-errors.csv', function ($handle) use ($errors) {
            $this->row($handle, ['Worksheet', 'Excel row', 'Validation errors']);
            foreach ($errors as $error) {
                $this->row($handle, [$error['sheet'] ?? '', $error['row'], implode(' ', $error['messages'])]);
            }
        });
    }

    public function csv(BackgroundTask $task, string $filename, callable $writer): void
    {
        $path = 'background-tasks/'.$task->id.'/result.csv';
        Storage::disk('local')->makeDirectory(dirname($path));
        $handle = fopen(Storage::disk('local')->path($path), 'wb');
        try {
            $writer($handle);
        } finally {
            fclose($handle);
        }
        $task->update(['file_path' => $path, 'filename' => $filename]);
    }

    public function row($handle, array $values): void
    {
        fputcsv($handle, array_map(fn ($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'".$value : $value, $values));
    }
}
