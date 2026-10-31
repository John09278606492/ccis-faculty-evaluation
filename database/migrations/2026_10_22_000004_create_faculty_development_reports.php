<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_development_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->json('details');
            $table->timestamps();
            $table->unique(['campus_id', 'user_id', 'semester_id'], 'faculty_development_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_development_reports');
    }
};
