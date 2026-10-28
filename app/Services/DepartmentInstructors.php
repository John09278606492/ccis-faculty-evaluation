<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DepartmentInstructors
{
    public function allowed(User $actor, Campus $campus, Department $department): bool
    {
        return ! $actor->isStudent() && $actor->canAccessTenant($campus)
            && $actor->can('update_user') && $actor->can('update', $department)
            && $department->is_active && (int) $department->campus_id === (int) $campus->id
            && app(AssignedDataAccess::class)->apply(Department::withoutGlobalScope('assigned_data'), $actor, $campus)->whereKey($department->id)->exists();
    }

    private function candidates(User $actor, Campus $campus, Department $department): Builder
    {
        return app(AssignedDataAccess::class)->apply(User::withoutGlobalScope('assigned_data'), $actor, $campus)
            ->where('is_instructor', true)->where('is_student', false)
            ->where(fn ($q) => $q->whereNull('instructor_department_id')->orWhere('instructor_department_id', $department->id));
    }

    public function options(User $actor, Campus $campus, Department $department): array
    {
        if (! $this->allowed($actor, $campus, $department)) {
            return [];
        }

        return $this->candidates($actor, $campus, $department)->whereNull('instructor_department_id')
            ->with(['roles.permissions', 'permissions'])->orderBy('name')->get()
            ->filter(fn ($user) => app(UserAccessAdministration::class)->canManageAccount($actor, $user, $campus))
            ->mapWithKeys(fn ($user) => [$user->id => $user->name.(filled($user->employee_id) ? ' ('.$user->employee_id.')' : '')])->all();
    }

    public function assign(User $actor, Campus $campus, Department $department, array $ids): int
    {
        Validator::make(['ids' => $ids], ['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['required', 'integer', 'min:1']])->validate();
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return DB::transaction(function () use ($actor, $campus, $department, $ids) {
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $department = Department::withoutGlobalScope('assigned_data')->findOrFail($department->id);
            abort_unless($this->allowed($actor, $campus, $department), 403);
            // Serialize SQLite writers before inspecting the current assignments.
            if (DB::getDriverName() === 'sqlite') {
                DB::table('departments')->where('id', $department->id)->update(['id' => $department->id]);
            }
            $users = $this->candidates($actor, $campus, $department)->whereKey($ids)->lockForUpdate()->with(['roles.permissions', 'permissions'])->get();
            if ($users->count() !== count($ids) || $users->contains(fn ($user) => ! app(UserAccessAdministration::class)->canManageAccount($actor, $user, $campus))) {
                throw ValidationException::withMessages(['ids' => 'Some instructors are unavailable or already belong to another home department. Reopen the list and select again.']);
            }
            $new = $users->whereNull('instructor_department_id');
            $count = User::withoutGlobalScope('assigned_data')->whereKey($new->modelKeys())->whereNull('instructor_department_id')
                ->update(['instructor_department_id' => $department->id, 'updated_at' => now()]);
            foreach ($new as $user) {
                app(UserAccessAdministration::class)->audit($actor, $user, $campus, 'home_department.assigned', ['department_id' => $department->id]);
            }
            if ($count) {
                ClassroomUpdates::panel('access', true);
            }

            return $count;
        });
    }
}
