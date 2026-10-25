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
        Schema::table('form_submissions', fn (Blueprint $table) => $table->text('evaluator_identity')->nullable());
        $permission = Permission::findOrCreate('view_evaluator_identity_form', 'web');
        foreach (Role::where('name', 'super_admin')->where('guard_name', 'web')->get() as $role) {
            $role->givePermissionTo($permission);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('form_submissions', fn (Blueprint $table) => $table->dropColumn('evaluator_identity'));
        Permission::where('name', 'view_evaluator_identity_form')->delete();
    }
};
