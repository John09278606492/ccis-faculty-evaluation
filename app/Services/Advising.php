<?php

namespace App\Services;

use App\Models\AdvisoryClass;
use App\Models\AdvisorySubmission;
use App\Models\AdvisoryTask;
use App\Models\User;
use App\Notifications\BellNotification;
use Filament\Notifications\Actions\Action as NotificationAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Academic advising: sections under an adviser and the requirements students hand in.
 * The section membership is the source of truth for who a task applies to.
 */
class Advising
{
    public const DISK = 'local';

    /** Sections of the campus that have no adviser yet, newest term first. */
    public function freeSections(?int $campusId, ?int $exceptClassId = null, ?int $semesterId = null): array
    {
        return DB::table('academic_sections as section')
            ->join('semesters as semester', 'semester.id', '=', 'section.semester_id')
            ->join('school_years as year', 'year.id', '=', 'semester.school_year_id')
            ->where('year.campus_id', $campusId)
            ->where(fn ($q) => $q->where('section.is_active', true)
                ->when($exceptClassId, fn ($q) => $q->orWhereIn('section.id', AdvisoryClass::whereKey($exceptClassId)->select('academic_section_id'))))
            ->whereNotIn('section.id', AdvisoryClass::when($exceptClassId, fn ($q) => $q->whereKeyNot($exceptClassId))->select('academic_section_id'))
            ->when($semesterId, fn ($q) => $q->where('semester.id', $semesterId))
            ->orderByDesc('semester.is_active')->orderByDesc('year.name')->orderBy('section.name')
            ->get(['section.id', 'section.name', 'semester.name as semester', 'year.name as year'])
            ->mapWithKeys(fn ($row) => [$row->id => "{$row->name} — {$row->semester} ({$row->year})"])
            ->all();
    }

    /** Change the roster source only before students have submitted work. */
    public function changeSection(AdvisoryClass $class, int $sectionId, User $actor): AdvisoryClass
    {
        Gate::forUser($actor)->authorize('update', $class);

        return DB::transaction(function () use ($class, $sectionId) {
            $class = AdvisoryClass::whereKey($class->id)->lockForUpdate()->firstOrFail();
            $tasks = $class->tasks()->lockForUpdate()->get();
            if ($class->academic_section_id === $sectionId) {
                return $class;
            }
            if (! array_key_exists($sectionId, $this->freeSections($this->campusIdOf($class), $class->id))) {
                throw ValidationException::withMessages(['academic_section_id' => 'Choose an available section in this campus.']);
            }
            if (AdvisorySubmission::whereIn('advisory_task_id', $tasks->modelKeys())->whereNotNull('submitted_at')->exists()) {
                throw ValidationException::withMessages(['academic_section_id' => 'Students have already submitted work for this section. Add a new advisory section for the new semester to keep their submissions in the correct term.']);
            }
            $class->update(['academic_section_id' => $sectionId]);
            foreach ($tasks as $task) {
                $this->syncRoster($task);
            }

            return $class;
        });
    }

    public function campusIdOf(AdvisoryClass $class): ?int
    {
        return DB::table('academic_sections as section')
            ->join('semesters as semester', 'semester.id', '=', 'section.semester_id')
            ->join('school_years as year', 'year.id', '=', 'semester.school_year_id')
            ->where('section.id', $class->academic_section_id)
            ->value('year.campus_id');
    }

    /** Adds students who joined the section and drops untouched rows of students who left it. */
    public function syncRoster(AdvisoryTask $task): void
    {
        $sectionId = $task->advisoryClass()->value('academic_section_id');
        $members = DB::table('student_section_memberships')->where('academic_section_id', $sectionId)->select('user_id');

        $missing = DB::table('users')
            ->whereIn('id', $members)
            ->whereNull('deleted_at')
            ->whereNotIn('id', AdvisorySubmission::where('advisory_task_id', $task->id)->select('user_id'))
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name']);

        $now = now();
        $names = app(AcknowledgementForms::class);
        foreach ($missing->chunk(200) as $chunk) {
            AdvisorySubmission::insertOrIgnore($chunk->map(fn ($student) => [
                'advisory_task_id' => $task->id,
                'user_id' => $student->id,
                'student_name' => $names->printedName($student),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }

        AdvisorySubmission::where('advisory_task_id', $task->id)
            ->where('status', 'pending')
            ->whereNotIn('user_id', $members)
            ->delete();
    }

    /** Tasks a student can see: those of the sections they belong to, in this campus. */
    public function tasksFor(User $student, ?int $campusId): Builder
    {
        return AdvisoryTask::query()
            ->where('status', '!=', 'archived')
            ->whereIn('advisory_class_id', AdvisoryClass::query()
                ->inCampus($campusId)
                ->whereIn('academic_section_id', DB::table('student_section_memberships')->where('user_id', $student->id)->select('academic_section_id'))
                ->select('id'));
    }

    /** Tasks still waiting for this student's files (not yet submitted, or returned for changes). */
    public function toDo(User $student, ?int $campusId): Builder
    {
        return $this->tasksFor($student, $campusId)
            ->where('status', 'open')
            ->where(fn ($query) => $query->where('allow_late', true)->orWhereNull('due_at')->orWhere('due_at', '>', now()))
            ->whereNotIn('id', AdvisorySubmission::where('user_id', $student->id)->whereIn('status', ['submitted', 'approved'])->select('advisory_task_id'));
    }

    public function toDoCount(User $student, ?int $campusId): int
    {
        return $this->toDo($student, $campusId)->count();
    }

    /**
     * Saves the student's files for a task. Sending again replaces the earlier files, until the adviser approves.
     *
     * @param  array<int, string>  $files  paths on the private disk
     */
    public function submit(AdvisoryTask $task, User $student, array $files, ?string $note): AdvisorySubmission
    {
        return DB::transaction(function () use ($task, $student, $files, $note) {
            $task = AdvisoryTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($student->isStudent() && $student->can('page_MyAdvising'), 403);
            $member = DB::table('student_section_memberships')
                ->where('user_id', $student->id)
                ->where('academic_section_id', $task->advisoryClass()->value('academic_section_id'))
                ->exists();
            $validFiles = array_values(array_unique(array_filter($files, fn ($path) => is_string($path) && str_starts_with($path, $this->folder($task, $student).'/') && ! str_contains($path, '..'))));
            if (count($validFiles) !== count($files) || count($files) > 10) {
                throw ValidationException::withMessages(['files' => 'Attach up to 10 valid files from your own workspace.']);
            }
            $files = $validFiles;
            if (! $member || ! $task->acceptsSubmissions()) {
                throw ValidationException::withMessages(['files' => 'This task is no longer accepting submissions.']);
            }
            if ($files === []) {
                throw ValidationException::withMessages(['files' => 'Attach at least one file.']);
            }
            foreach ($files as $path) {
                $disk = Storage::disk(self::DISK);
                if (! $disk->exists($path) || $disk->size($path) > 10 * 1024 * 1024 || ! in_array($disk->mimeType($path), ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
                    throw ValidationException::withMessages(['files' => 'Each file must be a screenshot (JPG, PNG, WebP) or PDF, up to 10 MB.']);
                }
            }

            $row = AdvisorySubmission::firstOrCreate(
                ['advisory_task_id' => $task->id, 'user_id' => $student->id],
                ['student_name' => app(AcknowledgementForms::class)->printedName($student), 'status' => 'pending'],
            );
            if ($row->status === 'approved') {
                throw ValidationException::withMessages(['files' => 'Your adviser already approved this submission.']);
            }

            $old = array_diff($row->files ?? [], $files);
            $row->update(['files' => $files, 'note' => filled($note) ? trim($note) : null, 'status' => 'submitted', 'submitted_at' => now(), 'feedback' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($old));

            return $row;
        });
    }

    public function folder(AdvisoryTask $task, User $student): string
    {
        return "advisory-submissions/{$task->id}/{$student->id}";
    }

    /** Approve, or return to the student with a note on what to fix. */
    public function review(AdvisorySubmission $submission, string $status, ?string $feedback, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $submission->task);
        if ($status === 'returned' && blank($feedback)) {
            throw ValidationException::withMessages(['feedback' => 'Explain what the student needs to change.']);
        }
        DB::transaction(function () use ($submission, $status, $feedback, $actor) {
            AdvisoryTask::whereKey($submission->advisory_task_id)->lockForUpdate()->firstOrFail();
            $submission = AdvisorySubmission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($status, ['approved', 'returned'], true) && $submission->submitted_at !== null, 422);
            $submission->update(['status' => $status, 'feedback' => filled($feedback) ? trim($feedback) : null, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);

            $task = $submission->task()->with('advisoryClass')->first();
            $student = User::withoutGlobalScope('assigned_data')->find($submission->user_id);
            if ($student) {
                $this->bell(collect([$student]), $task, $status === 'approved' ? 'Requirement approved' : 'Requirement returned: please fix and send again', $status === 'approved' ? 'success' : 'warning');
            }
        });
    }

    /** Tells every student who still owes this task, in their notification bell. */
    public function notifyWaiting(AdvisoryTask $task, string $title): int
    {
        if (! $task->acceptsSubmissions()) {
            return 0;
        }
        $students = User::withoutGlobalScope('assigned_data')
            ->whereIn('id', DB::table('student_section_memberships')->where('academic_section_id', $task->advisoryClass()->value('academic_section_id'))->select('user_id'))
            ->whereIn('id', AdvisorySubmission::where('advisory_task_id', $task->id)->whereIn('status', ['pending', 'returned'])->select('user_id'))
            ->get();
        if ($students->isEmpty()) {
            return 0;
        }
        $this->bell($students, $task, $title, 'warning');

        return $students->count();
    }

    private function bell($students, AdvisoryTask $task, string $title, string $tone): void
    {
        $campusId = $this->campusIdOf($task->advisoryClass);
        $due = $task->due_at ? ' · Due '.$task->due_at->timezone('Asia/Manila')->format('M j, Y g:ia') : '';

        // One transaction, so a whole section costs one disk commit on SQLite.
        DB::transaction(fn () => BellNotification::make()
            ->title($title)
            ->body($task->title.$due)
            ->{$tone}()
            ->actions([NotificationAction::make('open')->label('Open My Advising')->url("/{$campusId}/my-advising")->markAsRead()])
            ->sendToDatabase($students));
    }
}
