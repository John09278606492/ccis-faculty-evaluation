<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Student profile fields
            $table->string('student_id', 50)->nullable()->after('email');
            $table->string('first_name', 100)->nullable()->after('student_id');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('year_level', 20)->nullable()->after('last_name');
            $table->boolean('is_student')->default(false)->after('year_level');

            // Index for student lookups
            $table->index(['is_student']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_student']);
            $table->dropColumn(['student_id', 'first_name', 'last_name', 'year_level', 'is_student']);
        });
    }
};
