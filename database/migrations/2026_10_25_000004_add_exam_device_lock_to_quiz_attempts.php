<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An exam stays on the browser that started it; another browser signing in
     * with the same ID is blocked until the instructor allows it.
     */
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->string('device_hash', 64)->nullable();
            $table->string('blocked_device_hash', 64)->nullable();
            $table->unsignedInteger('device_blocks')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn(['device_hash', 'blocked_device_hash', 'device_blocks']);
        });
    }
};
