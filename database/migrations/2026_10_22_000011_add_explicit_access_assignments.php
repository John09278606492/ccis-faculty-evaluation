<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_campus_access')) {
            Schema::create('user_campus_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
                $table->string('scope', 20)->default('departments');
                $table->timestamps();
                $table->unique(['user_id', 'campus_id']);
            });
        }
        if (! Schema::hasTable('user_department_access')) {
            Schema::create('user_department_access', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['user_id', 'department_id']);
            });
        }
        if (! Schema::hasTable('access_audit_events')) {
            Schema::create('access_audit_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('subject_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action');
                $table->json('details')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
        $names = ['manage_access_assignments', 'assign_user_roles', 'view_academic_students', 'manage_academic_students', 'import_academic_students'];
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (Role::with('permissions')->where('guard_name', 'web')->get() as $role) {
            $role->loadMissing('permissions');
            $current = $role->permissions->pluck('name');
            $grants = [];
            if ($role->name === 'super_admin') {
                $grants = $names;
            } else {
                if ($current->contains('view_any_user')) {
                    $grants[] = 'view_academic_students';
                }
                if ($current->contains('create_user') || $current->contains('update_user')) {
                    $grants[] = 'manage_academic_students';
                }
                if ($current->contains('create_user') && $current->contains('update_user')) {
                    $grants[] = 'import_academic_students';
                }
            }
            // Spatie clears the loaded permissions relation after granting, so grant once.
            if ($grants !== []) {
                $role->givePermissionTo($grants);
            }
        }
        // Preserve known campus reviewer accounts without granting any new write actions.
        $reviewers = DB::table('users')->whereIn('email', ['sample.dean@balilihan.test', 'demo.dean@ccis.test'])->pluck('id');
        $admins = DB::table('model_has_roles')->where('model_type', User::class)
            ->whereIn('role_id', DB::table('roles')->select('id')->whereIn('name', ['admin', 'campus_admin']))->pluck('model_id');
        foreach (DB::table('campus_user')->whereIn('user_id', $reviewers->merge($admins)->unique())->get() as $membership) {
            DB::table('user_campus_access')->updateOrInsert(['user_id' => $membership->user_id, 'campus_id' => $membership->campus_id], ['scope' => 'campus', 'created_at' => now(), 'updated_at' => now()]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_events');
        Schema::dropIfExists('user_department_access');
        Schema::dropIfExists('user_campus_access');
    }
};
