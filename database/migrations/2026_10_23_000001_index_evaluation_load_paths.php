<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->index(['student_id', 'is_student'], 'student_entry_lookup'));
        Schema::table('course_offering_student', fn (Blueprint $table) => $table->index(['user_id', 'course_offering_id'], 'student_offering_lookup'));
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->index(['semester_id', 'teaching_department_id', 'is_active'], 'evaluation_period_department');
            $table->index(['user_id', 'semester_id', 'form_id'], 'faculty_period_instrument');
            $table->index(['evaluation_cycle_id', 'evaluation_type'], 'evaluation_cycle_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropIndex('student_entry_lookup'));
        Schema::table('course_offering_student', fn (Blueprint $table) => $table->dropIndex('student_offering_lookup'));
        Schema::table('course_offerings', function (Blueprint $table) {
            foreach (['evaluation_period_department', 'faculty_period_instrument', 'evaluation_cycle_type'] as $index) {
                $table->dropIndex($index);
            }
        });
    }
};
