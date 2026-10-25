<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Course;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportedCourseCatalogue
{
    public static function codeKey(string $code): string
    {
        return preg_replace('/\s+/u', '', mb_strtoupper(trim($code)));
    }

    private function authorize(User $actor, Campus $campus): void
    {
        abort_unless(! $actor->isStudent() && ! $actor->trashed() && $actor->canAccessTenant($campus)
            && $actor->can('view_academic_students') && $actor->can('manage_academic_students') && $actor->can('import_academic_students'), 403);
    }

    /** Catalogue identity is department + code; conflicting titles/units are never guessed. */
    public function review(User $actor, Campus $campus, array $rows, ?Collection $departments = null): array
    {
        $this->authorize($actor, $campus);
        $departments ??= app(AcademicStudents::class)->departments($actor, $campus)->get();
        $departments = $departments->keyBy(fn ($d) => ImportedDepartments::code($d->code));
        $targets = [];
        foreach ($rows as $row) {
            $department = $departments->get(ImportedDepartments::code((string) ($row['department_code'] ?? '')));
            if (! $department || ! is_array($row['subjects'] ?? null)) {
                continue;
            }
            foreach ($row['subjects'] as $subject) {
                if (! is_array($subject) || ! is_string($subject['subject_code'] ?? null) || ! is_string($subject['description'] ?? null)) {
                    continue;
                }
                $code = StudentAcademicRecords::code($subject['subject_code']);
                $name = trim($subject['description']);
                if ($code === '' || $name === '') {
                    continue;
                }
                $key = ($department->exists ? $department->id : 'new:'.$department->code).'|'.self::codeKey($code);
                $variant = StudentAcademicRecords::code($name).'|'.(is_numeric($subject['units'] ?? null) ? number_format((float) $subject['units'], 2, '.', '') : 'invalid');
                $targets[$key]['department'] = $department;
                $targets[$key]['variants'][$variant] = ['code' => $code, 'name' => $name, 'units' => $subject['units'] ?? null];
            }
        }
        $courses = $targets ? Course::withoutGlobalScope('assigned_data')->whereIn('department_id', $departments->pluck('id'))->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get() : collect();
        $byCode = $courses->groupBy(fn ($course) => $course->department_id.'|'.self::codeKey($course->code));
        $new = $warnings = [];
        $existing = 0;
        $canCreate = $actor->can('create_course');
        foreach ($targets as $key => $target) {
            $variants = array_values($target['variants']);
            $subject = $variants[0];
            $matches = $byCode->get($key, collect());
            $reason = null;
            if (count($variants) > 1) {
                $reason = 'The same code has different titles or units in this department. Confirm the correct course first.';
            } elseif ($matches->count() > 1) {
                $reason = 'Multiple existing courses have equivalent codes. Resolve the existing duplicates first.';
            } elseif ($matches->isNotEmpty()) {
                $course = $matches->first();
                if (StudentAcademicRecords::code($course->name) !== StudentAcademicRecords::code($subject['name']) || abs((float) $course->units - (float) $subject['units']) > .001) {
                    $reason = 'An existing course has this code but a different title or unit value. It will be kept unchanged.';
                } elseif (! $course->is_active) {
                    $reason = 'This course already exists but is inactive. It will not be reactivated by an import.';
                } else {
                    $existing++;
                }
            } elseif (mb_strlen($subject['code']) > 20 || mb_strlen($subject['name']) > 255 || ! is_numeric($subject['units'])
                || ! is_finite((float) $subject['units']) || (float) $subject['units'] < 0 || (float) $subject['units'] > 99.9
                || abs((float) $subject['units'] - round((float) $subject['units'], 1)) > .001) {
                $reason = 'Course limits: code up to 20 characters, title up to 255, and units from 0 to 99.9 with at most one decimal place.';
            } elseif (! $canCreate) {
                $reason = 'Creating missing courses requires the create_course permission. Student enrolments can still be imported.';
            } else {
                $new[] = ['department_id' => $target['department']->id, 'department' => $target['department']->code, ...$subject];
            }
            if ($reason) {
                $warnings[] = ['department' => $target['department']->code, 'code' => $subject['code'],
                    'titles' => implode(' | ', array_unique(array_column($variants, 'name'))), 'reason' => $reason];
            }
        }

        return ['new_courses' => count($new), 'existing_courses' => $existing, 'courses_needing_review' => count($warnings),
            'courses_to_create' => $new, 'course_warnings' => $warnings,
            'catalogue_state' => hash('sha256', json_encode([$canCreate, $courses->filter(fn ($c) => isset($targets[$c->department_id.'|'.self::codeKey($c->code)]))->values()->map(fn ($c) => $c->only(['id', 'department_id', 'code', 'name', 'units', 'is_active', 'updated_at']))->all(), $new, $warnings]))];
    }

    public function synchronize(User $actor, Campus $campus, array $rows): array
    {
        return DB::transaction(function () use ($actor, $campus, $rows) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $this->authorize($actor, $campus);
            app(AcademicStudents::class)->departments($actor, $campus)->lockForUpdate()->get();
            $plan = $this->review($actor, $campus, $rows);
            foreach ($plan['courses_to_create'] as $course) {
                Course::create(['department_id' => $course['department_id'], 'code' => $course['code'], 'name' => $course['name'], 'units' => $course['units'], 'is_active' => true]);
            }

            return $plan;
        });
    }

    private function storedRows(User $actor, Campus $campus, int $semesterId, array $departmentCodes): array
    {
        AcademicPeriod::validate($campus, $semesterId);
        $departments = app(AcademicStudents::class)->departments($actor, $campus)->get()->keyBy('id');
        abort_unless($departmentCodes !== [] && array_diff($departmentCodes, $departments->pluck('code')->all()) === [], 403);
        $students = app(AcademicStudents::class)->students($actor, $campus)->whereIn('student_department_id', $departments->whereIn('code', $departmentCodes)->keys())->select('users.id');
        $subjects = StudentSubjectEnrolment::where('semester_id', $semesterId)->whereIn('user_id', $students)
            ->join('users', 'users.id', '=', 'student_subject_enrolments.user_id')
            ->select('users.student_department_id', 'subject_code', 'description', 'student_subject_enrolments.units')->distinct()->get();

        return $subjects->groupBy('student_department_id')->map(fn ($group, $department) => ['department_code' => $departments[$department]->code,
            'subjects' => $group->map(fn ($s) => ['subject_code' => $s->subject_code, 'description' => $s->description, 'units' => $s->units])->all()])->values()->all();
    }

    public function previewStored(User $actor, Campus $campus, int $semesterId, array $departmentCodes): array
    {
        $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
        $this->authorize($actor, $campus);
        abort_unless($actor->can('create_course'), 403);
        $plan = $this->review($actor, $campus, $this->storedRows($actor, $campus, $semesterId, $departmentCodes));
        $token = (string) Str::uuid();
        Cache::put('imported-course-review:'.$token, ['actor' => $actor->id, 'campus' => $campus->id, 'semester' => $semesterId, 'departments' => $departmentCodes, 'plan' => $plan], now()->addMinutes(30));

        return ['token' => $token, ...$plan];
    }

    public function storedReview(User $actor, Campus $campus, string $token): array
    {
        $this->authorize($actor, $campus);
        $plan = Cache::get('imported-course-review:'.$token);
        abort_unless($plan && $plan['actor'] === $actor->id && $plan['campus'] === $campus->id, 403, 'Course review expired. Review again.');

        return $plan;
    }

    public function commitStored(User $actor, Campus $campus, string $token): array
    {
        $result = DB::transaction(function () use ($actor, $campus, $token) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $saved = $this->storedReview($actor, $campus, $token);
            abort_unless($actor->can('create_course'), 403);
            $rows = $this->storedRows($actor, $campus, $saved['semester'], $saved['departments']);
            $current = $this->review($actor, $campus, $rows);
            if ($current['catalogue_state'] !== $saved['plan']['catalogue_state']) {
                throw ValidationException::withMessages(['review_token' => 'Courses, enrolments or access changed. Review again before creating courses.']);
            }

            return $this->synchronize($actor, $campus, $rows);
        });
        Cache::forget('imported-course-review:'.$token);

        return $result;
    }

    public function commitFrozen(User $actor, Campus $campus, array $plan): array
    {
        abort_unless($plan['actor'] === $actor->id && $plan['campus'] === $campus->id, 403);
        $token = (string) Str::uuid();
        Cache::put('imported-course-review:'.$token, $plan, now()->addHour());
        try {
            return $this->commitStored($actor, $campus, $token);
        } finally {
            Cache::forget('imported-course-review:'.$token);
        }
    }
}
