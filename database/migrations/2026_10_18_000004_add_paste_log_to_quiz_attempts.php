<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Text pasted into an exam from outside it, such as an answer copied from another window.
            $table->unsignedSmallInteger('pastes')->default(0)->after('focus_log');
            $table->unsignedInteger('pasted_chars')->default(0)->after('pastes');
            $table->json('paste_log')->nullable()->after('pasted_chars');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['pastes', 'pasted_chars', 'paste_log']);
        });
    }
};
