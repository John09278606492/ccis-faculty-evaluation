<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->string('submission_fingerprint', 64)->nullable()->after('user_id');
            $table->unique(
                ['form_id', 'course_offering_id', 'submission_fingerprint'],
                'form_submissions_unique_evaluator'
            );
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropUnique('form_submissions_unique_evaluator');
            $table->dropColumn('submission_fingerprint');
        });
    }
};
