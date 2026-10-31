<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->string('finalized_reason', 20)->nullable();
            $table->index(['status', 'expires_at']);
        });
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->string('database_snapshot_status', 20)->nullable()->index();
            $table->text('database_snapshot_error')->nullable();
            $table->string('database_snapshot_hash', 64)->nullable();
            $table->timestamp('database_snapshot_at')->nullable();
            $table->timestamp('database_snapshot_attempted_at')->nullable();
        });
        Schema::table('student_sign_in_requests', function (Blueprint $table) {
            $table->string('verification_code', 8)->nullable()->index();
            $table->foreignId('quiz_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::table('quiz_answers')->whereNotNull('database_export')->where('database_export', '!=', '')->orderBy('id')->chunkById(100, function ($answers) {
            foreach ($answers as $answer) {
                DB::table('quiz_answers')->where('id', $answer->id)->update([
                    'database_snapshot_status' => 'ready',
                    'database_snapshot_hash' => hash('sha256', $answer->database_export),
                    'database_snapshot_at' => $answer->updated_at,
                ]);
            }
        });
        DB::table('quiz_answers')->whereNotNull('database_name')->whereNull('database_snapshot_status')
            ->whereIn('quiz_attempt_id', DB::table('quiz_attempts')->select('id')->where('status', '!=', 'in_progress'))
            ->update(['database_snapshot_status' => 'pending']);
    }

    public function down(): void
    {
        Schema::table('student_sign_in_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quiz_id');
            $table->dropColumn('verification_code');
        });
        Schema::table('quiz_answers', fn (Blueprint $table) => $table->dropColumn(['database_snapshot_status', 'database_snapshot_error', 'database_snapshot_hash', 'database_snapshot_at', 'database_snapshot_attempted_at']));
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn('finalized_reason');
        });
    }
};
