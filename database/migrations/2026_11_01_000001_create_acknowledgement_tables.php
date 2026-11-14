<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const INSTRUCTOR = ['view_any_acknowledgement::form', 'view_acknowledgement::form', 'create_acknowledgement::form', 'update_acknowledgement::form', 'delete_acknowledgement::form'];

    private const STUDENT = ['page_MyAcknowledgements'];

    /**
     * Learner's Acknowledgement Receipts (F-AQA-INS-003): one form per document given to
     * a class, one row per student on it. A signature is a single row, so signing never
     * rewrites the rest of the class.
     */
    public function up(): void
    {
        Schema::create('acknowledgement_forms', function (Blueprint $table) {
            $table->id();
            // Restricted, not cascaded: removing a class must never erase signed receipts.
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->string('document_title');
            $table->string('document_received');
            $table->date('date_received');
            $table->string('status', 16)->default('active');
            $table->string('year_section');
            $table->string('college_name');
            $table->string('department_name');
            $table->string('term_label');
            $table->string('instructor_name');
            $table->string('instructor_designation');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['course_offering_id', 'status']);
        });

        Schema::create('acknowledgement_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acknowledgement_form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Kept as printed, so a receipt does not change when an account is renamed.
            $table->string('student_name');
            $table->text('signature_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signed_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['acknowledgement_form_id', 'user_id']);
            $table->index(['acknowledgement_form_id', 'signed_at']);
            $table->index(['user_id', 'acknowledgement_form_id']);
        });

        foreach (['instructor' => self::INSTRUCTOR, 'student' => self::STUDENT, 'super_admin' => [...self::INSTRUCTOR, ...self::STUDENT]] as $role => $permissions) {
            $found = Role::where('name', $role)->where('guard_name', 'web')->first();
            $found?->givePermissionTo(array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $permissions));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('acknowledgement_recipients');
        Schema::dropIfExists('acknowledgement_forms');
        Permission::whereIn('name', [...self::INSTRUCTOR, ...self::STUDENT])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
