<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add instructor profile fields to users table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_instructor')->default(false)->after('is_student');
            $table->foreignId('instructor_department_id')
                ->nullable()
                ->after('is_instructor')
                ->constrained('departments')
                ->nullOnDelete();
            $table->string('employee_id', 50)->nullable()->after('instructor_department_id');
            $table->string('position')->nullable()->after('employee_id');
            $table->string('phone', 20)->nullable()->after('position');

            $table->index('is_instructor');
            $table->index('employee_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['instructor_department_id']);
            $table->dropIndex(['is_instructor']);
            $table->dropIndex(['employee_id']);
            $table->dropColumn([
                'is_instructor',
                'instructor_department_id',
                'employee_id',
                'position',
                'phone',
            ]);
        });
    }
};
