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
        Schema::create('class_record_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_record_upload_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('sheet_name');
            // Denormalized from the parent upload so lookups don't need a join.
            $table->string('period');
            // The exact header text (row label) used for the student ID column
            // in this sheet — e.g. "Student ID" — used to locate that column
            // by scanning the header row rather than hardcoding a letter.
            $table->string('student_id_header');
            $table->timestamps();

            $table->unique(['course_offering_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_record_sheets');
    }
};
