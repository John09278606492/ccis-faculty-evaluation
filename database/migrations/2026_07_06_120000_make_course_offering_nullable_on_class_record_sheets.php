<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('class_record_sheets', function (Blueprint $table) {
            $table->dropForeign(['course_offering_id']);
            $table->dropUnique(['course_offering_id', 'period']);
        });

        Schema::table('class_record_sheets', function (Blueprint $table) {
            // Null = detected on upload but not yet mapped to a course offering.
            $table->foreignId('course_offering_id')->nullable()->change();
            $table->foreign('course_offering_id')->references('id')->on('course_offerings')->nullOnDelete();
            $table->unique(['course_offering_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_record_sheets', function (Blueprint $table) {
            $table->dropForeign(['course_offering_id']);
            $table->dropUnique(['course_offering_id', 'period']);
        });

        Schema::table('class_record_sheets', function (Blueprint $table) {
            $table->foreignId('course_offering_id')->nullable(false)->change();
            $table->foreign('course_offering_id')->references('id')->on('course_offerings')->cascadeOnDelete();
            $table->unique(['course_offering_id', 'period']);
        });
    }
};
