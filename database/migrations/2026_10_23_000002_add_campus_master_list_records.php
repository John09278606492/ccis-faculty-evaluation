<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ID-only evaluation accounts have no password until a PIN is explicitly issued.
        Schema::table('users', fn (Blueprint $table) => $table->string('password')->nullable()->change());
        Schema::create('student_academic_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('program', 100)->nullable();
            $table->unsignedTinyInteger('reported_year_level')->nullable();
            $table->string('reported_sex', 30)->nullable();
            $table->decimal('total_units', 6, 2)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'semester_id']);
            $table->index(['semester_id', 'program', 'reported_year_level'], 'academic_record_cohort');
        });
        Schema::create('student_subject_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('subject_key', 64);
            $table->string('subject_code', 100);
            $table->string('description', 500);
            $table->decimal('lecture', 6, 2)->nullable();
            $table->decimal('laboratory', 6, 2)->nullable();
            $table->decimal('units', 6, 2)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'semester_id', 'subject_key'], 'student_period_subject');
            $table->index(['semester_id', 'subject_key'], 'subject_roster_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_subject_enrolments');
        Schema::dropIfExists('student_academic_records');
        // Nullable passwords safely represent accounts still awaiting PIN assignment.
    }
};
