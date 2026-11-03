<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\StudentAcademicRecord;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentAcademicRecords
{
    public static function code(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($value)));
    }

    public static function subjectKey(string $code, string $description): string
    {
        return hash('sha256', self::code($code).'|'.self::code($description));
    }

    public function errors(array $row): array
    {
        // Validate each subject once; expanding wildcard validators per row is costly
        // for the campus report's many repeated subject blocks.
        $errors = [];
        $present = fn ($v) => $v !== null && $v !== '';
        $numeric = fn ($v, $max) => is_numeric($v) && is_finite((float) $v) && (float) $v >= 0 && (float) $v <= $max;
        foreach (['program' => 100, 'reported_sex' => 30] as $field => $max) {
            if ($present($row[$field] ?? null) && (! is_string($row[$field]) || mb_strlen($row[$field]) > $max)) {
                $errors[] = $field.' must be text of at most '.$max.' characters.';
            }
        }
        if ($present($row['reported_year_level'] ?? null) && (! preg_match('/^\d+$/', (string) $row['reported_year_level']) || (int) $row['reported_year_level'] < 1 || (int) $row['reported_year_level'] > 10)) {
            $errors[] = 'Reported year level must be a whole number from 1 to 10.';
        }
        if ($present($row['total_units'] ?? null) && ! $numeric($row['total_units'], 150)) {
            $errors[] = 'Total Units must be a number from 0 to 150.';
        }
        if (array_key_exists('subjects', $row)) {
            if (! is_array($row['subjects']) || count($row['subjects']) > 50) {
                return [...$errors, 'Provide at most 50 subjects per student.'];
            }
            foreach ($row['subjects'] as $index => $subject) {
                if (! is_array($subject)) {
                    $errors[] = 'Subject '.($index + 1).' is not a valid subject block.';

                    continue;
                }
                foreach (['subject_code' => 100, 'description' => 500] as $field => $max) {
                    if (! is_string($subject[$field] ?? null) || trim($subject[$field]) === '' || mb_strlen($subject[$field]) > $max) {
                        $errors[] = 'Subject '.($index + 1).' '.$field.' is required and must be text of at most '.$max.' characters.';
                    }
                }
                foreach (['lecture', 'laboratory', 'units'] as $field) {
                    if (($field === 'units' || $present($subject[$field] ?? null)) && ! $numeric($subject[$field] ?? null, 100)) {
                        $errors[] = 'Subject '.($index + 1).' '.$field.' must be a number from 0 to 100.';
                    }
                }
            }
        }
        if (isset($row['subjects']) && $errors === []) {
            $keys = array_map(fn ($s) => self::subjectKey($s['subject_code'], $s['description']), $row['subjects']);
            if (count(array_unique($keys)) !== count($keys)) {
                $errors[] = 'The same subject is repeated for this student in the report.';
            }
            if (isset($row['total_units']) && $row['total_units'] !== '' && abs((float) $row['total_units'] - array_sum(array_column($row['subjects'], 'units'))) > .01) {
                $errors[] = 'Total Units does not equal the sum of the listed subject units.';
            }
        }

        return $errors;
    }

    public function save(User $student, int $semesterId, array $row): void
    {
        $keys = ['program', 'reported_year_level', 'reported_sex', 'total_units'];
        if (array_intersect($keys, array_keys($row)) === [] && ! array_key_exists('subjects', $row)) {
            return;
        }
        $data = array_intersect_key($row, array_flip($keys));
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        if (isset($row['subjects']) && ($data['total_units'] ?? null) === null) {
            $data['total_units'] = array_sum(array_column($row['subjects'], 'units'));
        }
        StudentAcademicRecord::updateOrCreate(['user_id' => $student->id, 'semester_id' => $semesterId], $data);
        if (array_key_exists('subjects', $row)) {
            StudentSubjectEnrolment::where('user_id', $student->id)->where('semester_id', $semesterId)->delete();
            $subjects = array_map(fn ($s) => ['user_id' => $student->id, 'semester_id' => $semesterId,
                'subject_key' => self::subjectKey($s['subject_code'], $s['description']), 'subject_code' => self::code($s['subject_code']),
                'description' => $s['description'], 'lecture' => ($s['lecture'] ?? '') === '' ? null : $s['lecture'], 'laboratory' => ($s['laboratory'] ?? '') === '' ? null : $s['laboratory'],
                'units' => $s['units'], 'created_at' => now(), 'updated_at' => now()], $row['subjects']);
            foreach (array_chunk($subjects, 80) as $chunk) {
                DB::table('student_subject_enrolments')->insert($chunk);
            }
            ClassroomUpdates::panel('users', true);
        }
    }

    private function authorize(User $actor, CourseOffering $offering): Campus
    {
        $offering->loadMissing('semester.schoolYear.campus', 'course', 'evaluationForm');
        $campus = $offering->campus;
        abort_unless($campus && $actor->can('manageEvaluators', $offering)
            && app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->whereKey($offering->id)->exists()
            && ! $offering->evaluationForm?->isSupervisorInstrument(), 403);

        return $campus;
    }

    public function subjectOptions(User $actor, CourseOffering $offering): array
    {
        return $this->matchingSubjects($actor, $offering)->groupBy(fn ($s) => self::code($s->description))->mapWithKeys(function ($group) {
            $subject = $group->first();

            return [$subject->subject_key => $subject->subject_code.' — '.$subject->description];
        })->all();
    }

    public function preferredSubject(User $actor, CourseOffering $offering): ?string
    {
        $subjects = $this->matchingSubjects($actor, $offering);
        $match = $subjects->first(fn ($s) => self::code($s->description) === self::code($offering->course?->name ?? ''));

        return $match?->subject_key;
    }

    private function matchingSubjects(User $actor, CourseOffering $offering)
    {
        $campus = $this->authorize($actor, $offering);
        if (! $offering->course) {
            return collect();
        }

        return StudentSubjectEnrolment::where('semester_id', $offering->semester_id)
            ->whereIn('user_id', app(AcademicStudents::class)->students($actor, $campus)->select('users.id'))
            ->whereRaw("REPLACE(UPPER(subject_code), ' ', '') = ?", [ImportedCourseCatalogue::codeKey($offering->course->code)])->select('subject_key', 'subject_code', 'description')->distinct()->get();
    }

    public function cohortOptions(User $actor, CourseOffering $offering, string $field, ?string $subjectKey = null, array $programs = []): array
    {
        $this->authorize($actor, $offering);
        abort_unless(in_array($field, ['program', 'reported_year_level'], true), 422);
        if (! $subjectKey) {
            return [];
        }

        $students = $this->candidates($actor, $offering, $subjectKey, $field === 'reported_year_level' ? $programs : []);

        return StudentAcademicRecord::where('semester_id', $offering->semester_id)
            ->whereIn('user_id', $students->select('users.id'))
            ->whereNotNull($field)->where($field, '<>', '')->distinct()->orderBy($field)->pluck($field, $field)->all();
    }

    public function candidates(User $actor, CourseOffering $offering, string $subjectKey, array $programs = [], array $years = [])
    {
        $campus = $this->authorize($actor, $offering);
        $subjects = $this->matchingSubjects($actor, $offering);
        $selected = $subjects->firstWhere('subject_key', $subjectKey);
        abort_unless($selected, 403, 'Choose a matching subject from the authorized master list.');
        $keys = $subjects->filter(fn ($s) => self::code($s->description) === self::code($selected->description))->pluck('subject_key');

        return app(AcademicStudents::class)->students($actor, $campus)
            ->whereHas('subjectEnrolments', fn ($q) => $q->where('semester_id', $offering->semester_id)->whereIn('subject_key', $keys))
            ->when($programs || $years, fn ($q) => $q->whereHas('academicRecords', fn ($q) => $q->where('semester_id', $offering->semester_id)
                ->when($programs, fn ($q) => $q->whereIn('program', $programs))->when($years, fn ($q) => $q->whereIn('reported_year_level', $years))));
    }

    public function addEvaluators(User $actor, CourseOffering $offering, string $subjectKey, array $programs = [], array $years = [], array $exclude = []): int
    {
        $ids = $this->candidates($actor, $offering, $subjectKey, $programs, $years)->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $exclude = array_map('intval', $exclude);
        abort_unless(array_diff($exclude, $ids) === [], 403);

        // The existing selection service rechecks permissions, campus, period and exclusions.
        return app(AcademicStudents::class)->assign($actor, $offering, [], array_values(array_diff($ids, $exclude)), [], 'add');
    }
}
