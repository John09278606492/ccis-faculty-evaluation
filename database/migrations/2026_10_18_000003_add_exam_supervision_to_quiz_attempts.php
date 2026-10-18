<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // How often and how long a student left the exam page while taking it.
            $table->unsignedSmallInteger('focus_leaves')->default(0)->after('ip_address');
            $table->unsignedInteger('focus_away_seconds')->default(0)->after('focus_leaves');
            $table->json('focus_log')->nullable()->after('focus_away_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['focus_leaves', 'focus_away_seconds', 'focus_log']);
        });
    }
};
