<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            // immediately | after_close | manual. Existing assessments keep showing scores right away.
            $table->string('results_visibility', 20)->default('immediately')->after('passing_score');
            $table->timestamp('results_released_at')->nullable()->after('results_visibility');
        });
    }

    public function down(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dropColumn(['results_visibility', 'results_released_at']);
        });
    }
};
