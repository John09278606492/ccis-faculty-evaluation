<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('academic_section_id')->nullable()->constrained('academic_sections')->nullOnDelete();
            $table->unsignedSmallInteger('remaining')->default(0);
            $table->string('subject');
            $table->text('message')->nullable();
            $table->string('email')->nullable();
            $table->string('email_status', 20)->default('none'); // none, queued, sent, failed
            $table->text('email_error')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['academic_section_id', 'created_at']);
            $table->index(['email_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_reminders');
    }
};
