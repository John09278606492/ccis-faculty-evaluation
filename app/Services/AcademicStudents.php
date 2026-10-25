<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\StudentSectionMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AcademicStudents
{
    public function students(User $actor, Campus $campus)
    {
        return app(AssignedDataAccess::class)->apply(User::query(), $actor, $campus)->where('is_student', true);
    }

    public function departments(User $actor, Campus $campus)
    {
        return app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->where('is_active', true);
    }

    /** Academic records must never be used to overwrite a legacy staff/student hybrid. */
    public function isSafeStudentTarget(User $student): bool
    {
        $student->loadMissing('roles.permissions', 'permissions');
        $access = app(UserAccessAdministration::class);

        return $student->is_student && ! $student->is_instructor
            && $access->rolesMatchProfile($student, $student->roles)
            && $student->permissions->pluck('name')->diff($access->studentPermissionNames())->isEmpty();
    }

    public function save(User $actor, Campus $campus, array $data, ?User $student = null): User
    {
        abort_unless(! $actor->isStudent() && $actor->canAccessTenant($campus)
            && $actor->can('manage_academic_students'), 403);
        if ($student) {
            // Re-read roles/profile flags rather than trusting a stale Livewire record.
            $student = $this->students($actor, $campus)->with('roles.permissions', 'permissions')->find($student->id);
            abort_unless($student, 403);
            if (! $this->isSafeStudentTarget($student)) {
                throw ValidationException::withMessages(['student_id' => 'This account has a staff or privileged profile. Correct its profile and roles in User Management first.']);
            }
        }
        $semesterId = AcademicPeriod::validate($campus, $data['semester_id'] ?? AcademicPeriod::current($campus));
        $data['student_id'] = trim((string) ($data['student_id'] ?? ''));
        $sections = $data['sections'] ?? ($data['section'] ?? []);
        if (is_string($sections)) {
            $sections = explode('|', $sections);
        }
        if (is_array($sections)) {
            $sections = array_values(array_filter(array_map(
                fn ($section) => is_string($section) ? strtoupper(preg_replace('/\s+/', ' ', trim($section))) : $section,
                $sections,
            ), fn ($section) => $section !== ''));
        }
        $data['sections'] = $sections;
        $data = Validator::make($data, [
            'student_id' => ['required', 'string', 'max:50', Rule::unique('users', 'student_id')->ignore($student?->id)],
            'first_name' => ['required', 'string', 'max:100'], 'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student?->id)],
            'student_department_id' => ['required', Rule::in($this->departments($actor, $campus)->pluck('id')->all())],
            'sections' => ['array'], 'sections.*' => ['required', 'string', 'max:50'],
        ])->validate();
        $data['sections'] = array_values(array_unique($data['sections']));

        return DB::transaction(function () use ($student, $data, $campus, $semesterId) {
            $record = $student ?? new User;
            if (! $record->exists) {
                // Evaluation entry uses ID only. Password/PIN login stays disabled until issuance.
                $record->password = null;
                $data['email'] = ($data['email'] ?? null) ?: 'student.'.Str::uuid().'@students.invalid';
            } elseif (empty($data['email'])) {
                unset($data['email']);
            }
            $record->fill($data);
            $record->is_student = true;
            $record->is_instructor = false;
            $record->save();
            $record->campuses()->syncWithoutDetaching([$campus->id]);
            $record->loadMissing('roles');
            $record->assignRole(Role::findOrCreate('student', 'web'));
            StudentSectionMembership::where('user_id', $record->id)->where('semester_id', $semesterId)->delete();
            foreach ($data['sections'] as $section) {
                StudentSectionMembership::create(['user_id' => $record->id, 'department_id' => $data['student_department_id'], 'semester_id' => $semesterId, 'section' => $section]);
            }

            return $record;
        });
    }

    /** Entire import succeeds or no student is changed. Never adopts another campus's account. */
    public function import(User $actor, Campus $campus, array $rows, ?int $semesterId = null): int
    {
        abort_unless($actor->can('import_academic_students') && $actor->can('manage_academic_students') && ! $actor->isStudent() && $actor->canAccessTenant($campus), 403);
        if (count($rows) > 5000) {
            throw ValidationException::withMessages(['file' => 'Import up to 5,000 students per file.']);
        }
        $semesterId = AcademicPeriod::validate($campus, $semesterId ?? AcademicPeriod::current($campus));

        return DB::transaction(function () use ($actor, $campus, $rows, $semesterId) {
            app(ImportedDepartments::class)->synchronize($actor, $campus, $rows);
            $departments = $this->departments($actor, $campus)->get()->keyBy(fn ($d) => ImportedDepartments::code($d->code));
            $count = 0;
            foreach ($rows as $index => $row) {
                if (collect($row)->every(fn ($value) => blank($value))) {
                    continue;
                }
                $department = $departments->get(ImportedDepartments::code($row['department_code'] ?? ''));
                if (! $department) {
                    throw ValidationException::withMessages(['file' => 'Row '.($index + 2).': department code is missing or outside your assignment.']);
                }
                $existing = User::withoutGlobalScope('assigned_data')->withTrashed()->where('student_id', trim($row['student_id'] ?? ''))->first();
                if ($existing && ! $this->students($actor, $campus)->whereKey($existing->id)->exists()) {
                    throw ValidationException::withMessages(['file' => 'Row '.($index + 2).': this ID already belongs to an account outside your access.']);
                }
                // Repeated/imported IDs may add more sections for irregular students.
                $incoming = $row['sections'] ?? ($row['section'] ?? '');
                $incoming = is_array($incoming) ? $incoming : explode('|', $incoming);
                $row['sections'] = array_merge($existing ? $this->sections($existing, $semesterId) : [], $incoming);
                $row['semester_id'] = $semesterId;
                try {
                    $academicErrors = app(StudentAcademicRecords::class)->errors($row);
                    if ($academicErrors) {
                        throw ValidationException::withMessages(['file' => $academicErrors]);
                    }
                    $saved = $this->save($actor, $campus, [...$row, 'student_department_id' => $department->id], $existing);
                    app(StudentAcademicRecords::class)->save($saved, $semesterId, $row);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['file' => 'Row '.($index + 2).': '.collect($e->errors())->flatten()->implode(' ')]);
                }
                $count++;
            }
            if (collect($rows)->contains(fn ($row) => ! empty($row['subjects']))) {
                app(ImportedCourseCatalogue::class)->synchronize($actor, $campus, $rows);
            }

            return $count;
        });
    }

    public function sections(User $student, int $semesterId): array
    {
        return StudentSectionMembership::where('user_id', $student->id)->where('semester_id', $semesterId)->orderBy('id')->pluck('section')->all();
    }

    public function sectionStats(User $actor, Campus $campus, ?int $semesterId = null): array
    {
        $semesterId ??= AcademicPeriod::current($campus);
        if (! $semesterId) {
            return [];
        }
        $semesterId = AcademicPeriod::validate($campus, $semesterId);
        $options = [];
        $sections = app(AcademicSections::class)->query($actor, $campus)->where('semester_id', $semesterId)->where('is_active', true)->with('department')
            ->withCount(['students' => fn ($q) => $q->whereIn('users.id', $this->students($actor, $campus)->select('users.id'))])->get();
        foreach ($sections as $section) {
            if ($section->department) {
                $options[$section->department_id.'~'.$semesterId.'~'.rawurlencode($section->name)] = [
                    'id' => $section->id, 'name' => $section->name, 'label' => $section->department->code.' · '.$section->name, 'students' => (int) $section->students_count,
                ];
            }
        }
        uasort($options, fn ($a, $b) => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }

    public function sectionOptions(User $actor, Campus $campus, ?int $semesterId = null, bool $withCounts = false): array
    {
        return array_map(fn ($s) => $s['label'].($withCounts ? ' · '.$s['students'].' students'.($s['students'] === 0 ? ' — upload list first' : '') : ''), $this->sectionStats($actor, $campus, $semesterId));
    }

    public function sectionAssignmentPreview(User $actor, CourseOffering $offering, array $groups, array $include = [], array $exclude = []): array
    {
        $preview = $this->selection($actor, $offering, $groups, $include, $exclude, 'replace');
        if ($groups === []) {
            throw ValidationException::withMessages(['sections' => 'Choose the sections taught by this faculty member.']);
        }
        $stats = $this->sectionStats($actor, $offering->campus, $offering->semester_id);
        $empty = collect($groups)->filter(fn ($key) => $stats[$key]['students'] === 0)->map(fn ($key) => $stats[$key]['name']);
        if ($empty->isNotEmpty()) {
            throw ValidationException::withMessages(['sections' => $empty->join(', ').' has no students yet. Upload its student list in Sections and confirm the background review before assigning it to a faculty member.']);
        }

        return $preview;
    }

    public function assignSections(User $actor, CourseOffering $offering, array $groups, array $include = [], array $exclude = []): int
    {
        $this->sectionAssignmentPreview($actor, $offering, $groups, $include, $exclude);

        return DB::transaction(function () use ($actor, $offering, $groups, $include, $exclude) {
            // Serialize roster writes and recheck section membership immediately before saving.
            if (DB::getDriverName() === 'sqlite') {
                DB::table('course_offerings')->where('id', $offering->id)->update(['id' => $offering->id]);
            } else {
                CourseOffering::whereKey($offering)->lockForUpdate()->firstOrFail();
            }
            $offering = CourseOffering::query()->findOrFail($offering->id);
            $this->sectionAssignmentPreview($actor, $offering, $groups, $include, $exclude);
            $count = $this->assign($actor, $offering, $groups, $include, $exclude, 'replace');
            $offering->update(['evaluator_selection' => [...$offering->evaluator_selection, 'source' => 'sections_v1']]);

            return $count;
        });
    }

    public function selection(User $actor, CourseOffering $offering, array $groups, array $include = [], array $exclude = [], string $mode = 'add'): array
    {
        $offering->loadMissing('semester.schoolYear.campus', 'evaluationForm');
        $campus = $offering->campus;
        abort_unless($campus && $actor->can('manageEvaluators', $offering)
            && app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->whereKey($offering->id)->exists()
            && ! $offering->evaluationForm?->isSupervisorInstrument(), 403);
        abort_unless(in_array($mode, ['add', 'replace'], true), 422);
        $eligible = $this->students($actor, $campus);
        $options = $this->sectionOptions($actor, $campus, $offering->semester_id);
        abort_unless(array_diff($groups, array_keys($options)) === [], 403);
        $include = array_map('intval', $include);
        $exclude = array_map('intval', $exclude);
        $custom = array_values(array_unique(array_merge($include, $exclude)));
        abort_unless((clone $eligible)->whereKey($custom)->count() === count($custom), 403);
        $selected = collect($include);
        foreach ($groups as $group) {
            [$department, $semester, $section] = explode('~', $group, 3);
            $members = StudentSectionMembership::where('department_id', $department)->where('semester_id', $semester)->where('section', rawurldecode($section))->select('user_id');
            $selected = $selected->merge((clone $eligible)->whereIn('users.id', $members)->pluck('id'));
        }
        $selected = $selected->unique()->diff($exclude)->values();
        $current = $offering->students()->pluck('users.id');
        $final = ($mode === 'add' ? $current->merge($selected)->unique() : $selected)->diff($exclude)->values();

        return ['ids' => $final->all(), 'selected' => $selected->count(), 'total' => $final->count(),
            'added' => $final->diff($current)->count(), 'removed' => $current->diff($final)->count(),
            'excluded' => count($exclude), 'groups' => array_values(array_intersect_key($options, array_flip($groups))), 'mode' => $mode];
    }

    public function assign(User $actor, CourseOffering $offering, array $groups, array $include = [], array $exclude = [], string $mode = 'add'): int
    {
        $preview = $this->selection($actor, $offering, $groups, $include, $exclude, $mode);
        DB::transaction(function () use ($offering, $preview, $groups, $include, $exclude, $mode) {
            $roster = collect($preview['ids'])->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]])->all();
            // A later active-roster edit must not silently detach deleted students' history.
            foreach ($offering->historicalStudents()->onlyTrashed()->pluck('users.id') as $id) {
                $roster[$id] = [];
            }
            $offering->students()->sync($roster);
            $offering->update(['evaluator_selection' => ['sections' => $groups, 'include' => $include, 'exclude' => $exclude, 'mode' => $mode, 'count' => $preview['total'], 'saved_at' => now()->toIso8601String()]]);
        });

        return $preview['selected'];
    }
}
