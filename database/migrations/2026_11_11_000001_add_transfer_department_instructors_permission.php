<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // Off for every other role until an administrator ticks it under Roles.
        $permission = Permission::findOrCreate('transfer_department_instructors', 'web');
        foreach (Role::where('name', 'super_admin')->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'transfer_department_instructors')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
