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
        Schema::create('class_record_quiz_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_record_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained()->cascadeOnDelete();
            // Spreadsheet column letter (e.g. "D") this quiz's raw score occupies.
            $table->string('column_letter', 3);
            $table->timestamps();

            $table->unique(['class_record_sheet_id', 'quiz_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_record_quiz_columns');
    }
};
