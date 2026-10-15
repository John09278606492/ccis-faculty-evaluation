<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->boolean('auto_sync_class_record')->default(true);
            $table->timestamp('class_record_synced_at')->nullable();
            $table->text('class_record_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', fn (Blueprint $table) => $table->dropColumn(['auto_sync_class_record', 'class_record_synced_at', 'class_record_sync_error']));
    }
};
