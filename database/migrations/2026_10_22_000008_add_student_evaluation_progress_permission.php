<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate('page_StudentEvaluationProgress', 'web');
        $monitor = Role::findOrCreate('evaluation_progress_monitor', 'web');
        $monitor->givePermissionTo($permission);
        $headIds = Department::query()->get(['supervisor_id', 'chairperson_id'])
            ->flatMap(fn ($department) => [$department->supervisor_id, $department->chairperson_id])->filter()->unique();
        foreach (User::whereIn('id', $headIds)->where('is_student', false)->with('roles')->get() as $head) {
            $head->assignRole($monitor);
        }
        foreach (Role::where('name', 'super_admin')->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'page_StudentEvaluationProgress')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
