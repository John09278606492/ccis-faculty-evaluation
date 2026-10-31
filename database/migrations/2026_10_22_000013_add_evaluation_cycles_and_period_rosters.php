<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_section_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('section', 50);
            $table->timestamps();
            $table->unique(['user_id', 'department_id', 'semester_id', 'section'], 'student_period_section_unique');
            $table->index(['department_id', 'semester_id']);
        });
        DB::table('users')->where('is_student', true)->whereNotNull('student_department_id')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                $department = DB::table('departments')->where('id', $user->student_department_id)->first();
                $semester = $department ? DB::table('semesters')->join('school_years', 'school_years.id', '=', 'semesters.school_year_id')
                    ->where('school_years.campus_id', $department->campus_id)->where('semesters.is_active', true)
                    ->orderByDesc('school_years.is_current')->orderByDesc('semesters.start_date')->select('semesters.id')->first() : null;
                if (! $semester) {
                    continue;
                }
                foreach (json_decode($user->sections ?? '[]', true) ?: [] as $section) {
                    DB::table('student_section_memberships')->insertOrIgnore(['user_id' => $user->id, 'department_id' => $department->id, 'semester_id' => $semester->id, 'section' => $section, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
        Schema::table('forms', function (Blueprint $table) {
            $table->foreignId('previous_version_id')->nullable()->constrained('forms')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
        Schema::table('form_submissions', fn (Blueprint $table) => $table->json('instrument_snapshot')->nullable());
        Schema::create('evaluation_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('semester_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('draft')->index();
            $table->foreignId('student_form_id')->nullable()->constrained('forms')->restrictOnDelete();
            $table->foreignId('supervisor_form_id')->nullable()->constrained('forms')->restrictOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreignId('evaluation_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('teaching_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->json('evaluator_selection')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluation_cycle_id');
            $table->dropConstrainedForeignId('teaching_department_id');
            $table->dropColumn('evaluator_selection');
        });
        Schema::dropIfExists('evaluation_cycles');
        Schema::table('form_submissions', fn (Blueprint $table) => $table->dropColumn('instrument_snapshot'));
        Schema::table('forms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_version_id');
            $table->dropColumn(['version', 'published_at', 'archived_at']);
        });
        Schema::dropIfExists('student_section_memberships');
    }
};
