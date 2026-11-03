<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
        Schema::create('faculty_source_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->string('source_campus_id', 50);
            $table->string('external_id', 50);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->timestamps();
            $table->unique(['campus_id', 'source_campus_id', 'external_id'], 'faculty_source_identity');
        });
        Schema::create('teaching_class_source_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_offering_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 64)->unique();
            $table->json('metadata');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->whereNull('email')->exists()) {
            throw new RuntimeException('Complete the email addresses of imported faculty profiles before rolling back this migration.');
        }
        Schema::dropIfExists('teaching_class_source_links');
        Schema::dropIfExists('faculty_source_links');
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable(false)->change());
    }
};
