<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserAccessAdministration
{
    public function canManageAccount(User $actor, User $subject, Campus $campus): bool
    {
        if (! $actor->canAccessTenant($campus) || ! $subject->campuses()->whereKey($campus->id)->exists()) {
            return false;
        }
        if ($actor->isSuperAdmin()) {
            return true;
        }
        if ($subject->isSuperAdmin()) {
            return false;
        }
        if (! app(AssignedDataAccess::class)->apply(User::withoutGlobalScope('assigned_data'), $actor, $campus)->whereKey($subject->id)->exists()) {
            return false;
        }
        if ($subject->is_student || $actor->id === $subject->id) {
            return true;
        }

        return $subject->getAllPermissions()->pluck('name')->diff($actor->getAllPermissions()->pluck('name'))->isEmpty();
    }

    public function studentPermissionNames(): array
    {
        return array_merge(RolesSeeder::STUDENT, ['page_MyAssessments', 'page_TakeAssessment']);
    }

    public function rolesMatchProfile(User $subject, $roles): bool
    {
        if (! $subject->is_student) {
            return ! $roles->contains('name', 'student');
        }
        $safe = $this->studentPermissionNames();

        return $roles->every(fn ($role) => ! in_array($role->name, ['super_admin', 'instructor'], true)
            && $role->permissions->pluck('name')->diff($safe)->isEmpty());
    }

    public function enforceProfileRoles(User $subject): void
    {
        $subject->loadMissing('roles.permissions', 'permissions');
        $safe = $subject->roles->filter(fn ($role) => $this->rolesMatchProfile($subject, collect([$role])));
        $subject->syncRoles($safe);
        if ($subject->is_student) {
            $subject->syncPermissions($subject->permissions->whereIn('name', $this->studentPermissionNames()));
        }
        $subject->loadMissing('roles');
        $profile = $subject->is_student ? 'student' : ($subject->is_instructor ? 'instructor' : null);
        if ($profile) {
            $subject->assignRole(Role::findOrCreate($profile, 'web'));
        }
    }

    public function grantableRoles(User $actor, Campus $campus)
    {
        $query = Role::query()->with('permissions');
        if ($actor->isSuperAdmin()) {
            return $query;
        }
        if (! $actor->can('assign_user_roles') || ! $actor->canAccessTenant($campus)) {
            return $query->whereRaw('1 = 0');
        }
        $permissionIds = $actor->getAllPermissions()->pluck('id');

        return $query->where('name', '!=', 'super_admin')->where('team_id', $campus->id)
            ->whereDoesntHave('permissions', fn ($q) => $q->whereNotIn('permissions.id', $permissionIds));
    }

    public function assignRoles(User $actor, User $subject, Campus $campus, array $roleIds): void
    {
        abort_unless($actor->canAccessTenant($campus) && ($actor->isSuperAdmin() || ($actor->can('assign_user_roles') && $actor->id !== $subject->id)), 403);
        abort_unless($this->canManageAccount($actor, $subject, $campus), 403);
        $ids = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = $this->grantableRoles($actor, $campus)->whereKey($ids)->get();
        abort_unless($roles->count() === count($ids), 403, 'You may grant only roles within your own authority.');
        abort_unless($this->rolesMatchProfile($subject, $roles), 403, 'Student accounts cannot receive staff roles, and staff accounts cannot receive the student profile role.');
        abort_if(! $actor->isSuperAdmin() && $subject->isSuperAdmin(), 403);
        $subject->loadMissing('roles');
        if ($subject->isSuperAdmin() && ! $roles->contains('name', 'super_admin')) {
            abort_unless(User::role('super_admin')->count() > 1, 403, 'The last super administrator must retain their role.');
        }
        // A campus role editor cannot strip global or another campus's roles.
        $preserved = $actor->isSuperAdmin() ? collect() : $subject->roles->filter(fn ($role) => $role->team_id === null || (int) $role->team_id !== $campus->id);
        $subject->syncRoles($roles->merge($preserved)->unique('id'));
        $this->audit($actor, $subject, $campus, 'roles.changed', ['role_ids' => $roles->modelKeys()]);
        ClassroomUpdates::panel('access', true);
    }

    public function setScope(User $actor, User $subject, Campus $campus, string $scope, array $departmentIds): void
    {
        abort_unless(Schema::hasTable('user_campus_access') && $actor->can('manage_access_assignments') && ! $actor->isStudent() && $actor->canAccessTenant($campus), 403);
        abort_unless($subject->campuses()->whereKey($campus->id)->exists() && ! $subject->isStudent(), 403);
        abort_if(! $actor->isSuperAdmin() && ($actor->id === $subject->id || $subject->isSuperAdmin()), 403);
        $allowed = app(AssignedDataAccess::class)->departmentIds($actor, $campus);
        abort_unless(in_array($scope, ['campus', 'departments'], true) && ($scope !== 'campus' || $allowed === null), 403);
        $ids = array_values(array_unique(array_map('intval', $departmentIds)));
        $valid = DB::table('departments')->where('campus_id', $campus->id)->whereIn('id', $ids)->pluck('id')->all();
        abort_unless(count($valid) === count($ids) && ($allowed === null || array_diff($ids, $allowed) === []), 403);
        DB::transaction(function () use ($subject, $campus, $scope, $ids) {
            DB::table('user_campus_access')->updateOrInsert(['user_id' => $subject->id, 'campus_id' => $campus->id], ['scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('user_department_access')->where('user_id', $subject->id)->whereIn('department_id', DB::table('departments')->select('id')->where('campus_id', $campus->id))->delete();
            foreach ($scope === 'departments' ? $ids : [] as $id) {
                DB::table('user_department_access')->insert(['user_id' => $subject->id, 'department_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->audit($actor, $subject, $campus, 'scope.changed', ['scope' => $scope, 'department_ids' => $ids]);
        ClassroomUpdates::panel('access', true);
    }

    public function validateRolePermissions(User $actor, Campus $campus, array $permissions): void
    {
        abort_unless($actor->canAccessTenant($campus), 403);
        $valid = Permission::where('guard_name', 'web')->whereIn('name', $permissions)->pluck('name')->all();
        abort_unless(count(array_unique($permissions)) === count($valid), 403, 'Unknown permission selected.');
        if (! $actor->isSuperAdmin()) {
            abort_unless(array_diff($permissions, $actor->getAllPermissions()->pluck('name')->all()) === [], 403, 'You cannot delegate permissions outside your own authority.');
        }
    }

    public function audit(User $actor, User $subject, Campus $campus, string $action, array $details): void
    {
        if (Schema::hasTable('access_audit_events')) {
            DB::table('access_audit_events')->insert(['actor_id' => $actor->id, 'subject_id' => $subject->id, 'campus_id' => $campus->id, 'action' => $action, 'details' => json_encode($details), 'created_at' => now()]);
        }
    }
}
