<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Checks a teaching class's evaluators against section lists (a section title such as "BSIT 3A" above an ID No. column)
 * and, when asked, adds the listed students who are missing, removes students not on any list, and fills pending report sections.
 * Students who already submitted an evaluation for this class are never removed.
 */
class ClassRosterVerification
{
    private function authorize(User $actor, CourseOffering $class): void
    {
        abort_unless($actor->can('manageEvaluators', $class) && ! $class->evaluationForm?->isSupervisorInstrument(), 403);
    }

    /** @param  array<int, array{name: string, title: ?string, ids: array<int, string>}>  $files */
    public function compare(User $actor, CourseOffering $class, array $files): array
    {
        $this->authorize($actor, $class);
        $campus = $class->campus;
        $semester = $class->semester_id;
        $listed = [];
        $fileInfo = [];
        foreach ($files as $index => $file) {
            $match = $file['title'] ? app(AcademicSections::class)->matchTitle($actor, $campus, $semester, $file['title'], $file['ids']) : ['section' => null, 'problem' => 'No section name above the ID No. column, so report sections will not be filled from this file.'];
            $fileInfo[$index] = ['name' => $file['name'], 'title' => $file['title'], 'count' => count($file['ids']),
                'section_id' => $match['section']?->id, 'section' => $match['section']?->name, 'problem' => $match['problem']];
            foreach ($file['ids'] as $id) {
                $listed[mb_strtoupper(trim($id))][] = $index;
            }
        }
        $withSections = ['studentSectionMemberships' => fn ($q) => $q->where('semester_id', $semester)];
        $found = collect();
        foreach (array_chunk(array_keys($listed), 400) as $chunk) {
            $found = $found->merge(app(AcademicStudents::class)->students($actor, $campus)->whereIn('student_id', $chunk)->with($withSections)->get());
        }
        $found = $found->filter(fn ($s) => app(AcademicStudents::class)->isSafeStudentTarget($s))->keyBy(fn ($s) => mb_strtoupper($s->student_id));
        $current = $class->students()->with($withSections)->get()->keyBy('id');
        $submitted = $this->submitted($class, $current->keys()->all());
        $sections = app(EvaluationSections::class);
        $row = fn (User $s, array $extra = []) => ['user_id' => $s->id, 'id' => $s->student_id, 'name' => $s->name,
            'sections' => $s->studentSectionMemberships->pluck('section')->unique()->values()->all(), ...$extra];

        $verified = $missing = $pending = [];
        foreach ($listed as $id => $indexes) {
            $id = (string) $id;
            $student = $found->get($id);
            if (! $student) {
                continue;
            }
            $sectionFile = collect($indexes)->first(fn ($i) => $fileInfo[$i]['section_id']);
            if ($current->has($student->id)) {
                $verified[] = $student->id;
                if ($sectionFile !== null && ! $sections->forStudent($class, $current[$student->id])) {
                    $pending[] = $row($student, ['section' => $fileInfo[$sectionFile]['section'], 'file' => $sectionFile]);
                }
            } else {
                $missing[] = $row($student, ['file' => $indexes[0], 'section' => $sectionFile !== null ? $fileInfo[$sectionFile]['section'] : null,
                    'section_file' => $sectionFile]);
            }
        }
        $unknown = array_values(array_diff(array_map('strval', array_keys($listed)), array_map('strval', $found->keys()->all())));
        $extra = $current->reject(fn ($s) => isset($listed[mb_strtoupper($s->student_id)]))
            ->map(fn ($s) => $row($s, ['submitted' => isset($submitted[$s->id])]))->sortBy('id')->values()->all();

        return ['files' => array_values($fileInfo), 'listed' => count($listed), 'current' => $current->count(), 'verified' => count($verified),
            'missing' => collect($missing)->sortBy('id')->values()->all(), 'extra' => $extra, 'unknown' => $unknown,
            'pending' => collect($pending)->sortBy('id')->values()->all(),
            'identical' => $missing === [] && $extra === [] && $unknown === []];
    }

    /** Students of this class who already submitted its evaluation (submissions keep only a per-class fingerprint). */
    private function submitted(CourseOffering $class, array $userIds): array
    {
        $fingerprints = FormSubmission::withoutGlobalScopes()->where('course_offering_id', $class->id)->pluck('submission_fingerprint')->filter()->flip();
        $identity = app(EvaluationSubmissionIdentity::class);

        return collect($userIds)->filter(fn ($id) => $fingerprints->has($identity->forUser($class, $id)))->flip()->all();
    }

    /** @return array{added: int, removed: int, kept: int, sections: int} */
    public function apply(User $actor, CourseOffering $class, array $files, bool $add, bool $remove, bool $fillSections): array
    {
        return DB::transaction(function () use ($actor, $class, $files, $add, $remove, $fillSections) {
            $class = $class->fresh();
            $result = $this->compare($actor, $class, $files);
            $added = $removed = $sectioned = 0;
            if ($add && $result['missing']) {
                $ids = array_column($result['missing'], 'user_id');
                $class->students()->syncWithoutDetaching(collect($ids)->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]])->all());
                $added = count($ids);
            }
            $removable = array_values(array_filter($result['extra'], fn ($s) => ! $s['submitted']));
            if ($remove && $removable) {
                $class->students()->detach(array_column($removable, 'user_id'));
                $removed = count($removable);
            }
            if ($fillSections) {
                $targets = [...$result['pending'], ...($add ? array_filter($result['missing'], fn ($s) => $s['section_file'] !== null) : [])];
                foreach (collect($targets)->groupBy('section') as $rows) {
                    $file = $result['files'][$rows->first()['section_file'] ?? $rows->first()['file']];
                    $section = app(AcademicSections::class)->section($actor, $class->campus, $file['section_id']);
                    $ids = app(AcademicSections::class)->eligible($actor, $class->campus, $section)->whereKey($rows->pluck('user_id'))->pluck('users.id')->all();
                    if ($ids) {
                        app(EvaluationSections::class)->assign($actor, $class->fresh(), $ids, $section->id);
                        $sectioned += count($ids);
                    }
                }
            }
            if ($added || $removed) {
                // A checked list is the class roster now; later automatic imports must not undo it.
                $class = $class->fresh();
                $class->update(['evaluator_selection' => [...($class->evaluator_selection ?? []), 'auto_section_roster' => false, 'auto_enrolment' => false]]);
            }

            return ['added' => $added, 'removed' => $removed, 'kept' => count($result['extra']) - ($remove ? $removed : 0), 'sections' => $sectioned];
        });
    }
}
