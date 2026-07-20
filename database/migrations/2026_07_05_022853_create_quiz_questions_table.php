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
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // multiple_choice, true_false, short_answer, essay, file_upload
            $table->text('question_text');
            $table->unsignedInteger('points')->default(1);
            $table->integer('order')->default(0);
            $table->json('options')->nullable(); // multiple_choice: [{key,label}]
            $table->json('correct_answer')->nullable(); // MC/TF: key(s); short_answer: accepted list
            $table->timestamps();

            $table->index(['quiz_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_questions');
    }
};
