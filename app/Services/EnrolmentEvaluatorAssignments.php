<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrolmentEvaluatorAssignments
{
    public function subjectOptions(User $actor, CourseOffering $class): array
    {
        $records = app(StudentAcademicRecords::class);
        $key = $records->preferredSubject($actor, $class);

        return $key ? [$key => $class->course->code.' - '.$class->course->name] : [];
    }

    public function preview(User $actor, CourseOffering $class, string $subjectKey, array $programs = []): array
    {
        abort_unless(array_key_exists($subjectKey, $this->subjectOptions($actor, $class)), 422, 'The enrolled subject title and code must match this teaching class. Review the subject catalogue first.');
        $students = app(StudentAcademicRecords::class)->candidates($actor, $class, $subjectKey, $programs)
            ->with(['roles.permissions', 'permissions', 'studentSectionMemberships' => fn ($q) => $q->where('semester_id', $class->semester_id)])
            ->orderBy('users.id')->get()->filter(fn ($student) => app(AcademicStudents::class)->isSafeStudentTarget($student));
        $class->loadMissing('course');
        $sameSubject = CourseOffering::withoutGlobalScope('assigned_data')->where('semester_id', $class->semester_id)->where('is_active', true)
            ->whereHas('course', fn ($q) => $q->withoutGlobalScope('assigned_data')->whereHas('department', fn ($d) => $d->withoutGlobalScope('assigned_data')->where('campus_id', $class->campus->id)))
            ->with(['course', 'evaluationForm'])->get()->filter(fn ($c) => $c->course && ImportedCourseCatalogue::codeKey($c->course->code) === ImportedCourseCatalogue::codeKey($class->course->code)
                && StudentAcademicRecords::code($c->course->name) === StudentAcademicRecords::code($class->course->name)
                && (int) $c->evaluation_cycle_id === (int) $class->evaluation_cycle_id && ! $c->evaluationForm?->isSupervisorInstrument());
        $siblings = $sameSubject->where('user_id', $class->user_id)->where('id', '!=', $class->id)->pluck('id');
        $elsewhere = DB::table('course_offering_student')->whereIn('course_offering_id', $siblings)->pluck('user_id')->flip();
        $current = $class->students()->pluck('users.id')->flip();
        $rows = [];
        foreach ($students as $student) {
            $matches = $sameSubject->filter(function ($candidate) use ($student) {
                $groups = $candidate->evaluator_selection['sections'] ?? [];

                return $student->studentSectionMemberships->contains(fn ($m) => in_array($m->department_id.'~'.$m->semester_id.'~'.rawurlencode($m->section), $groups, true)
                    || (filled($candidate->section) && TeachingClassRosterImports::section($m->section) === TeachingClassRosterImports::section($candidate->section)));
            });
            $status = $current->has($student->id) ? 'Already assigned'
                : ($elsewhere->has($student->id) ? 'Assigned to another class of this faculty'
                    : (($sameSubject->count() === 1 || ($matches->count() === 1 && $matches->first()->id === $class->id)) ? 'Ready to link' : 'Confirm teacher / class'));
            $section = app(EvaluationSections::class)->forStudent($class, $student);
            $rows[] = ['id' => $student->id, 'student_id' => $student->student_id, 'name' => $student->name, 'section' => $section,
                'status' => $status, 'automatic' => $status === 'Ready to link', 'eligible' => ! $elsewhere->has($student->id) || $current->has($student->id)];
        }
        $hash = hash('sha256', json_encode([$class->only(['id', 'user_id', 'course_id', 'semester_id', 'evaluation_cycle_id', 'evaluator_selection']),
            $subjectKey, $programs, $rows, $sameSubject->map(fn ($c) => $c->only(['id', 'user_id', 'section', 'is_active', 'evaluator_selection']))->values()->all()]));

        return ['rows' => $rows, 'automatic_ids' => array_column(array_filter($rows, fn ($r) => $r['automatic']), 'id'),
            'eligible_ids' => array_column(array_filter($rows, fn ($r) => $r['eligible']), 'id'), 'hash' => $hash];
    }

    public function assign(User $actor, CourseOffering $class, string $subjectKey, array $programs, array $selectedIds, string $reviewHash): int
    {
        return DB::transaction(function () use ($actor, $class, $subjectKey, $programs, $selectedIds, $reviewHash) {
            // Campus serialization also protects against matching the same student
            // to two section classes of the same faculty in concurrent requests.
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $class->campus->id)->update(['id' => $class->campus->id]);
            } else {
                Campus::whereKey($class->campus->id)->lockForUpdate()->firstOrFail();
            }
            $class = $class->fresh();
            $preview = $this->preview($actor, $class, $subjectKey, $programs);
            if (! hash_equals($preview['hash'], $reviewHash)) {
                throw ValidationException::withMessages(['subject_key' => 'Enrolments, class assignments or sections changed. Review the matches again.']);
            }
            $ids = array_values(array_unique(array_map('intval', $selectedIds)));
            abort_unless($ids && array_diff($ids, $preview['eligible_ids']) === [], 422, 'Select students from the reviewed enrolled-subject matches.');
            $current = $class->students()->pluck('users.id')->all();
            $new = array_values(array_diff($ids, $current));
            foreach (array_chunk($new, 400) as $chunk) {
                $class->students()->attach(collect($chunk)->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]])->all());
            }
            $selection = $class->evaluator_selection ?? [];
            $sections = $selection['report_sections'] ?? [];
            foreach ($preview['rows'] as $row) {
                if (in_array($row['id'], $new, true)) {
                    $sections[$row['id']] = $row['section'];
                }
            }
            $class->update(['evaluator_selection' => [...$selection, 'source' => 'subject_enrolments_v1', 'auto_section_roster' => false, 'auto_enrolment' => false,
                'report_sections' => $sections, 'matched_subject_key' => $subjectKey, 'matched_programs' => $programs,
                'count' => count($current) + count($new), 'saved_at' => now()->toIso8601String()]]);
            ClassroomUpdates::panel('setup');

            return count($new);
        });
    }
}
