<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('supervise_attempts')->default(false)->after('shuffle_questions');
        });

        // Exam supervision log: when a student left the exam page and for how long,
        // and text pasted from outside the exam.
        Schema::create('quiz_attempt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('seconds')->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['quiz_attempt_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_events');
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropColumn('supervise_attempts'));
    }
};
