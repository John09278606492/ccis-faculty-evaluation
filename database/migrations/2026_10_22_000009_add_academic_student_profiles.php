<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('student_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('section', 50)->nullable();
            $table->index(['student_department_id', 'year_level', 'section'], 'student_academic_group');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('student_academic_group');
            $table->dropConstrainedForeignId('student_department_id');
            $table->dropColumn('section');
        });
    }
};
