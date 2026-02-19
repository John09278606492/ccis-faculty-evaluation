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
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rating_criteria_id')->constrained('rating_criteria')->cascadeOnDelete();
            $table->unsignedTinyInteger('score'); // 1-5 rating scale
            $table->text('comment')->nullable();
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamps();

            // Each student can only rate each criteria once per course offering
            $table->unique(['course_offering_id', 'student_id', 'rating_criteria_id'], 'ratings_unique');
            $table->index(['course_offering_id', 'rating_criteria_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
