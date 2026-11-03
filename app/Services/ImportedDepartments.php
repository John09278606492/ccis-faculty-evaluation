<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ImportedDepartments
{
    public static function code(string $value): string
    {
        return preg_replace('/\s+/u', '', mb_strtoupper(trim($value)));
    }

    public function canCreate(User $actor, Campus $campus): bool
    {
        return ! $actor->isStudent() && ! $actor->trashed() && $actor->canAccessTenant($campus)
            && $actor->can('view_academic_students') && $actor->can('manage_academic_students')
            && $actor->can('import_academic_students') && $actor->can('create_department')
            && app(AssignedDataAccess::class)->departmentIds($actor, $campus) === null;
    }

    /** Preview only: no records or access assignments are created here. */
    public function review(User $actor, Campus $campus, array $rows): array
    {
        $targets = [];
        foreach ($rows as $row) {
            if (collect($row)->every(fn ($value) => blank($value))) {
                continue;
            }
            $code = self::code(is_string($row['department_code'] ?? null) ? $row['department_code'] : '');
            $name = trim(is_string($row['department_name'] ?? null) ? $row['department_name'] : '');
            $targets[$code]['names'][StudentAcademicRecords::code($name)] = $name;
        }
        ksort($targets);
        $all = Department::withoutGlobalScope('assigned_data')->where('campus_id', $campus->id)->orderBy('id')->get();
        $matches = $all->groupBy(fn ($d) => self::code($d->code));
        $allowed = app(AcademicStudents::class)->departments($actor, $campus)->pluck('departments.id')->all();
        $canCreate = $this->canCreate($actor, $campus);
        $new = $errors = [];
        $existing = 0;
        foreach ($targets as $code => $target) {
            $found = $matches->get($code, collect());
            $names = array_filter($target['names'], fn ($name) => $name !== '');
            if ($code === '' || mb_strlen($code) > 20 || ! preg_match('/^[\pL\pN][\pL\pN._&\/-]*$/u', $code)) {
                $errors[$code] = 'Use a college/department code of 1–20 letters or numbers, with only . _ & / - as separators.';
            } elseif (count($names) > 1 || collect($names)->contains(fn ($name) => mb_strlen($name) > 255 || preg_match('/[\x00-\x1F]/', $name))) {
                $errors[$code] = 'Department names conflict in this file or exceed 255 characters. Correct the department name first.';
            } elseif ($found->count() > 1) {
                $errors[$code] = 'Multiple departments in this campus have equivalent codes. Resolve the duplicates first.';
            } elseif ($found->isNotEmpty()) {
                $department = $found->first();
                if (! $department->is_active || ! in_array($department->id, $allowed, true)) {
                    $errors[$code] = 'This department is inactive or outside your assigned departments. Imports cannot reactivate it or expand your access.';
                } else {
                    $existing++;
                }
            } elseif (! $canCreate) {
                $errors[$code] = 'Adding a missing department requires campus-wide access and create_department permission, together with student-import permissions.';
            } else {
                // The campus report often supplies only a code; never invent its full college name.
                $new[] = ['code' => $code, 'name' => $names ? reset($names) : $code];
            }
        }

        return ['new_departments' => count($new), 'existing_departments' => $existing,
            'departments_to_create' => $new, 'department_errors' => $errors,
            'department_state' => hash('sha256', json_encode([$canCreate, $allowed, $all->map(fn ($d) => $d->only(['id', 'code', 'name', 'is_active', 'updated_at']))->all(), $new, $errors]))];
    }

    public function available(User $actor, Campus $campus, array $plan): Collection
    {
        $departments = app(AcademicStudents::class)->departments($actor, $campus)->get()
            ->reject(fn ($d) => isset($plan['department_errors'][self::code($d->code)]))
            ->keyBy(fn ($d) => self::code($d->code));
        foreach ($plan['departments_to_create'] as $attributes) {
            $departments->put($attributes['code'], new Department(['campus_id' => $campus->id, ...$attributes, 'is_active' => true]));
        }

        return $departments;
    }

    public function synchronize(User $actor, Campus $campus, array $rows): array
    {
        return DB::transaction(function () use ($actor, $campus, $rows) {
            // Serialize catalogue creation per campus before reading candidate codes.
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $plan = $this->review($actor, $campus, $rows);
            if ($plan['department_errors']) {
                throw ValidationException::withMessages(['file' => array_values($plan['department_errors'])]);
            }
            foreach ($plan['departments_to_create'] as $attributes) {
                Department::create(['campus_id' => $campus->id, ...$attributes, 'is_active' => true]);
            }

            return $plan;
        });
    }
}
