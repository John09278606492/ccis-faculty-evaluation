<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->string('context', 64);
            $table->text('payload');
            $table->string('base_revision', 64);
            $table->uuid('revision');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'campus_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_drafts');
    }
};
