<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Section-to-teacher configuration used by the in-page sectioning panel. */
class FacultySectioning
{
    public function allowed(User $actor, Campus $campus): bool
    {
        return app(AutomaticEvaluatorAssignments::class)->allowed($actor, $campus)
            && $actor->can('view_academic_students') && $actor->can('manage_academic_students');
    }

    public function anchor(User $actor, Campus $campus, int $id): CourseOffering
    {
        abort_unless($this->allowed($actor, $campus), 403);
        $class = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->with(['course.department', 'semester.schoolYear', 'evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'evaluationForm', 'evaluationCycle'])->findOrFail($id);
        abort_unless($class->course && ! $class->evaluationForm?->isSupervisorInstrument() && $actor->can('manageEvaluators', $class), 403);
        abort_if(! $class->is_active || ($class->evaluationCycle && ! in_array($class->evaluationCycle->status, ['draft', 'published'], true)), 409, 'This evaluation is closed.');

        return $class;
    }

    public function group(User $actor, Campus $campus, CourseOffering $anchor)
    {
        return app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->where('course_id', $anchor->course_id)
            ->where('semester_id', $anchor->semester_id)->where('is_active', true)
            ->when($anchor->evaluation_cycle_id, fn ($q) => $q->where('evaluation_cycle_id', $anchor->evaluation_cycle_id), fn ($q) => $q->whereNull('evaluation_cycle_id'))
            ->whereHas('evaluatee', fn ($q) => $q->withoutGlobalScope('assigned_data')->where('is_instructor', true)->where('is_student', false))
            ->with(['evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'course', 'evaluationForm'])->withCount('students')->get()->reject(fn ($c) => $c->evaluationForm?->isSupervisorInstrument());
    }

    public function sections(User $actor, Campus $campus, CourseOffering $anchor, ?int $facultyId = null)
    {
        $group = $this->group($actor, $campus, $anchor);

        return app(AcademicSections::class)->query($actor, $campus)->where('department_id', $anchor->teaching_department_id ?? $anchor->course->department_id)
            ->where('semester_id', $anchor->semester_id)->where('is_active', true)->get()->filter(function ($section) use ($facultyId, $group) {
                if (! $facultyId) {
                    return true;
                }
                $owners = $group->filter(fn ($c) => TeachingClassRosterImports::section($c->section ?? '') === TeachingClassRosterImports::section($section->name))->pluck('user_id')->unique();

                return $owners->isEmpty() || $owners->contains($facultyId);
            });
    }

    public function candidates(User $actor, Campus $campus, CourseOffering $anchor)
    {
        $subject = app(StudentAcademicRecords::class)->preferredSubject($actor, $anchor);
        if (! $subject) {
            return collect();
        }

        return app(StudentAcademicRecords::class)->candidates($actor, $anchor, $subject)
            ->where('student_department_id', $anchor->teaching_department_id ?? $anchor->course->department_id)
            ->with(['roles.permissions', 'permissions', 'studentSectionMemberships' => fn ($q) => $q->where('semester_id', $anchor->semester_id)])
            ->orderBy('name')->get()->filter(fn ($s) => app(AcademicStudents::class)->isSafeStudentTarget($s));
    }

    public function createSection(User $actor, Campus $campus, int $anchorId, string $name): int
    {
        $anchor = $this->anchor($actor, $campus, $anchorId);

        return app(AcademicSections::class)->save($actor, $campus, ['name' => $name, 'department_id' => $anchor->teaching_department_id ?? $anchor->course->department_id,
            'semester_id' => $anchor->semester_id, 'is_active' => true])->id;
    }

    private function context(User $actor, Campus $campus, array $data): array
    {
        $anchor = $this->anchor($actor, $campus, (int) $data['anchor_id']);
        $group = $this->group($actor, $campus, $anchor);
        $facultyId = (int) $data['faculty_id'];
        abort_unless($group->pluck('user_id')->contains($facultyId), 403);
        $section = $this->sections($actor, $campus, $anchor, $facultyId)->firstWhere('id', (int) $data['section_id']);
        if (! $section) {
            throw ValidationException::withMessages(['section_id' => 'Choose an active section available to this instructor and academic period.']);
        }

        return [$anchor, $group, $facultyId, $section];
    }

    public function importFile(User $actor, Campus $campus, array $data, string $source): array
    {
        [$anchor, , , $section] = $this->context($actor, $campus, $data);
        app(AcademicSections::class)->authorize($actor, $campus, true, true);
        $parsed = app(SectionRosterImports::class)->prepare($actor, $campus, $section->id, $source);
        $eligible = $this->candidates($actor, $campus, $anchor)->keyBy(fn ($s) => mb_strtoupper($s->student_id));
        $review = $parsed['review'];
        $ids = [];
        foreach ($review['details'] as &$row) {
            if (! $eligible->has($row['student_id'])) {
                $row['messages'][] = 'This student is not enrolled in this subject in the selected campus/department and academic period.';
            }
            if (! $row['messages']) {
                $ids[] = $eligible[$row['student_id']]->id;
            }
        }
        unset($row);
        $errors = count(array_filter($review['details'], fn ($r) => $r['messages'] !== []));
        if ($errors) {
            return ['validation_error_count' => $errors, 'issues' => $review['details'], 'message' => 'No students were assigned. '.$errors.' rows need correction. Review the issues here and upload again.'];
        }

        return $this->assign($actor, $campus, $data, array_unique($ids), $parsed['plan']);
    }

    public function assign(User $actor, Campus $campus, array $data, array $ids, ?array $filePlan = null): array
    {
        return DB::transaction(function () use ($actor, $campus, $data, $ids, $filePlan) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            [$anchor, $group, $facultyId, $section] = $this->context($actor, $campus, $data);
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $eligible = $this->candidates($actor, $campus, $anchor)->keyBy('id');
            if (! $ids || array_diff($ids, $eligible->keys()->all())) {
                throw ValidationException::withMessages(['student_ids' => 'Select enrolled students from this subject, department and academic period.']);
            }
            if ($filePlan) {
                $checked = app(TeachingClassRosterImports::class)->analyzeRows($actor, $campus, $anchor->semester_id, $filePlan['rows'], $section->department_id, $section->name);
                if ($checked['review']['error_count'] || $checked['state_hash'] !== $filePlan['state_hash']) {
                    throw ValidationException::withMessages(['file' => 'The masterlist changed while validating. Upload the section list again.']);
                }
            }
            $targets = $group->where('user_id', $facultyId)->filter(fn ($c) => TeachingClassRosterImports::section($c->section ?? '') === TeachingClassRosterImports::section($section->name));
            if ($targets->count() > 1) {
                throw ValidationException::withMessages(['section_id' => 'Duplicate teaching classes exist for this instructor/section. Resolve the duplicate before assigning students.']);
            }
            $target = $targets->first();
            if (! $target) {
                abort_unless($actor->can('create_course::offering'), 403);
                if (mb_strlen($section->name) > 20) {
                    throw ValidationException::withMessages(['section_id' => 'Use a section name of at most 20 characters for the teaching-class reference.']);
                }
                $target = CourseOffering::create(['course_id' => $anchor->course_id, 'semester_id' => $anchor->semester_id, 'user_id' => $facultyId,
                    'teaching_department_id' => $section->department_id, 'section' => $section->name, 'is_active' => true,
                    'evaluation_cycle_id' => $anchor->evaluation_cycle_id, 'form_id' => $anchor->form_id, 'evaluation_type' => $anchor->evaluation_type]);
            }
            $retained = [];
            foreach ($group->where('id', '!=', $target->id) as $other) {
                $overlap = $other->students()->whereKey($ids)->pluck('users.id')->all();
                if (! $overlap) {
                    continue;
                }
                foreach ($overlap as $id) {
                    $fingerprint = app(EvaluationSubmissionIdentity::class)->forUser($other, (int) $id);
                    $submitted = $other->formSubmissions()->withoutGlobalScope('assigned_data')->where(fn ($q) => $q->where('submission_fingerprint', $fingerprint)->orWhere('user_id', $id))->exists();
                    if ($submitted) {
                        if ($other->user_id !== $facultyId) {
                            throw ValidationException::withMessages(['student_ids' => 'A selected student has already evaluated another instructor for this subject. Submitted evaluations cannot be moved to a different teacher.']);
                        }
                        app(EvaluationSections::class)->assign($actor, $other, [$id], $section->id);
                        $retained[] = $id;
                    } else {
                        if ($other->ratings()->withoutGlobalScope('assigned_data')->where('user_id', $id)->exists()) {
                            throw ValidationException::withMessages(['student_ids' => 'A selected student has recorded ratings in another class. Existing evaluation records cannot be moved.']);
                        }
                        if ($other->quizzes()->withoutGlobalScope('assigned_data')->exists()) {
                            throw ValidationException::withMessages(['student_ids' => 'A selected student has assessment records in another class. Its classroom enrolment cannot be removed by sectioning.']);
                        }
                        $other->students()->detach($id);
                    }
                }
            }
            app(AcademicSections::class)->add($actor, $campus, $section, $ids);
            $attach = array_values(array_diff($ids, $retained));
            foreach (array_chunk($attach, 400) as $chunk) {
                $target->students()->syncWithoutDetaching(collect($chunk)->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]])->all());
            }
            $selection = $target->evaluator_selection ?? [];
            $labels = $selection['report_sections'] ?? [];
            foreach ($attach as $id) {
                $labels[$id] = $section->name;
            }
            // An explicit instructor list is authoritative. Subject matching must
            // not grow it with students the operator did not select or upload.
            $finalIds = $target->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $sectionIds = $section->students()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
            $target->update(['evaluator_selection' => [...$selection, 'source' => 'sections_v1', 'auto_enrolment' => false, 'auto_section_roster' => false, 'sectioning_policy' => true,
                'sections' => [$section->department_id.'~'.$section->semester_id.'~'.rawurlencode($section->name)],
                'include' => array_values(array_diff($finalIds, $sectionIds)), 'exclude' => array_values(array_diff($sectionIds, $finalIds)),
                'mode' => 'replace', 'count' => count($finalIds),
                'report_sections' => $labels, 'sectioned_by' => $actor->id, 'sectioned_at' => now()->toIso8601String()]]);
            // This operation changes only the reviewed subject/teacher roster.
            // A campus-wide match would also alter unrelated teaching classes.
            ClassroomUpdates::panel('setup');

            return ['validation_error_count' => 0, 'students' => count($ids), 'class_id' => $target->id,
                'message' => count($ids).' students assigned to '.$section->name.' and its instructor. '.count($retained).' completed evaluations kept in their original class with the section recorded.'];
        });
    }
}
