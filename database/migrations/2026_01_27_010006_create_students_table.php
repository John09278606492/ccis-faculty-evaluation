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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->string('student_id', 50);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('year_level', 20)->nullable(); // e.g., "1st Year", "2nd Year"
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['campus_id', 'student_id']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
