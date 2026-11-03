<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'deleted_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->softDeletes());
        }
        Permission::findOrCreate('delete_academic_students', 'web');
        foreach (Role::with('permissions')->where('guard_name', 'web')->get() as $role) {
            $permissions = $role->permissions->pluck('name');
            if ($role->name === 'super_admin' || ($permissions->contains('manage_academic_students') && $permissions->contains('delete_user'))) {
                $role->givePermissionTo('delete_academic_students');
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Removing deletion markers would reactivate accounts. Keep the column and history.
    }
};
