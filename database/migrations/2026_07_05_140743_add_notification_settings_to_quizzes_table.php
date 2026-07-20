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
        Schema::table('quizzes', function (Blueprint $table) {
            // How long before available_from to alert enrolled students. Null = no alert.
            $table->unsignedInteger('notify_minutes_before')->nullable()->after('available_until');
            // Guards against sending the same alert twice; cleared if available_from changes.
            $table->timestamp('notified_at')->nullable()->after('notify_minutes_before');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['notify_minutes_before', 'notified_at']);
        });
    }
};
