<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisory_tasks', function (Blueprint $table) {
            // Which kinds of files students may upload for the task. Empty means screenshots and PDF, as before.
            $table->json('accepted_types')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('advisory_tasks', function (Blueprint $table) {
            $table->dropColumn('accepted_types');
        });
    }
};
