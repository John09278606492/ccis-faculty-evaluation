<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make course_id nullable in course_offerings so that
     * non-instructor evaluatees can be assigned without a course.
     */
    public function up(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            // Drop the existing foreign key
            $table->dropForeign(['course_id']);

            // Make the column nullable and re-add FK with nullOnDelete
            $table->foreignId('course_id')->nullable()->change();
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropForeign(['course_id']);

            $table->foreignId('course_id')->nullable(false)->change();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });
    }
};
