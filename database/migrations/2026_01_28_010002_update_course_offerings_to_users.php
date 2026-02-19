<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Change course_offerings.instructor_id to user_id referencing users table.
     */
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            // Drop the old foreign key constraint
            $table->dropForeign(['instructor_id']);

            // Rename the column
            $table->renameColumn('instructor_id', 'user_id');
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            // Add new foreign key constraint to users table
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->renameColumn('user_id', 'instructor_id');
        });

        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreign('instructor_id')
                ->references('id')
                ->on('instructors')
                ->cascadeOnDelete();
        });
    }
};
