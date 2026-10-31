<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Explicit authorization for queue workers: no Filament tenant/global-scope dependency. */
class ScopedAccountImport
{
    public function context(User $actor, array $options, string $profile): array
    {
        abort_unless(in_array($profile, ['student', 'instructor'], true), 403);
        $campus = Campus::find($options['campus_id'] ?? null);
        $permission = $profile === 'student' ? 'import_academic_students' : 'create_user';
        abort_unless($campus && ! $actor->isStudent() && $actor->canAccessTenant($campus) && $actor->can($permission), 403);
        $department = app(AssignedDataAccess::class)->apply(Department::withoutGlobalScope('assigned_data'), $actor, $campus)
            ->whereKey($options['department_id'] ?? null)->first();
        if (! $department) {
            throw ValidationException::withMessages(['department_id' => 'Choose a department within your campus and assignments.']);
        }

        return [$campus, $department];
    }

    public function resolve(User $actor, array $options, string $profile, array $data): User
    {
        [$campus] = $this->context($actor, $options, $profile);
        $identifier = $profile === 'student' ? 'student_id' : 'employee_id';
        $email = $data['email'] ?? null;
        $id = $data[$identifier] ?? null;
        $byId = filled($id) ? User::withoutGlobalScope('assigned_data')->withTrashed()->where($identifier, $id)->first() : null;
        $byEmail = filled($email) ? User::withoutGlobalScope('assigned_data')->withTrashed()->where('email', $email)->first() : null;
        if ($byId && $byEmail && $byId->id !== $byEmail->id) {
            throw ValidationException::withMessages([$identifier => 'The ID and email belong to different existing accounts.']);
        }
        $record = $byId ?? $byEmail;
        if (! $record) {
            return new User;
        }
        $visible = app(AssignedDataAccess::class)->apply(User::withoutGlobalScope('assigned_data'), $actor, $campus)->whereKey($record->id)->exists();
        if ($record->trashed() || $record->isSuperAdmin() || ! $visible || ($profile === 'student' ? ! $record->is_student : $record->is_student) || ($byId && $record->email !== $email)) {
            throw ValidationException::withMessages([$identifier => 'This existing account cannot be changed by this import.']);
        }
        abort_unless($actor->can($profile === 'student' ? 'manage_academic_students' : 'update_user')
            && app(UserAccessAdministration::class)->canManageAccount($actor, $record, $campus), 403);

        return $record;
    }

    public function prepare(User $actor, User $record, array $options, string $profile): void
    {
        [, $department] = $this->context($actor, $options, $profile);
        if (! $record->password) {
            $record->password = Str::random(40);
            $record->must_change_password = $profile !== 'student';
        }
        $record->is_student = $profile === 'student';
        $record->is_instructor = $profile === 'instructor';
        if ($profile === 'student') {
            $record->student_department_id = $department->id;
            $record->instructor_department_id = null;
        } else {
            $record->instructor_department_id = $department->id;
        }
    }

    public function finish(User $actor, User $record, array $options, string $profile): void
    {
        [$campus] = $this->context($actor, $options, $profile);
        $record->campuses()->syncWithoutDetaching([$campus->id]);
        $record->loadMissing('roles');
        $ids = $options['role_ids'] ?? [];
        if ($ids !== []) {
            app(UserAccessAdministration::class)->assignRoles($actor, $record, $campus, $ids);
        } else {
            // The standard profile role is safe; imports never replace other roles.
            $record->assignRole(Role::findOrCreate($profile, 'web'));
        }
    }
}
