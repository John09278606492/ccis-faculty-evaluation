<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const ADVISER = [
        'view_any_advisory::class', 'view_advisory::class', 'create_advisory::class', 'update_advisory::class', 'delete_advisory::class',
        'view_any_advisory::task', 'view_advisory::task', 'create_advisory::task', 'update_advisory::task', 'delete_advisory::task',
    ];

    private const STUDENT = ['page_MyAdvising'];

    /**
     * Academic advising: a section under an adviser, tasks the adviser sets for it (requirements
     * with a due date), and one submission row per student per task.
     */
    public function up(): void
    {
        Schema::create('advisory_classes', function (Blueprint $table) {
            $table->id();
            // One adviser per section. Restricted: removing a section must not erase submissions.
            $table->foreignId('academic_section_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('adviser_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('adviser_id');
        });

        Schema::create('advisory_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisory_class_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->json('requirements')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->string('consequence')->nullable();
            $table->boolean('allow_late')->default(false);
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['advisory_class_id', 'status']);
        });

        Schema::create('advisory_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advisory_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('student_name');
            $table->string('status', 16)->default('pending');
            $table->json('files')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->text('feedback')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['advisory_task_id', 'user_id']);
            $table->index(['advisory_task_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        foreach (['instructor' => self::ADVISER, 'student' => self::STUDENT, 'super_admin' => [...self::ADVISER, ...self::STUDENT]] as $role => $permissions) {
            $found = Role::where('name', $role)->where('guard_name', 'web')->first();
            $found?->givePermissionTo(array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_submissions');
        Schema::dropIfExists('advisory_tasks');
        Schema::dropIfExists('advisory_classes');
        Permission::whereIn('name', [...self::ADVISER, ...self::STUDENT])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
