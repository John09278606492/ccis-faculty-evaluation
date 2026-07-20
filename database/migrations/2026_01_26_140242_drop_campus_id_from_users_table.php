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
        Schema::table('users', function (Blueprint $table) {
            // Drop the old campus_id column if it exists
            if (Schema::hasColumn('users', 'campus_id')) {
                $table->dropForeign(['campus_id']);
                $table->dropIndex(['campus_id']);
                $table->dropColumn('campus_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Rollback if needed
            if (! Schema::hasColumn('users', 'campus_id')) {
                $table->foreignId('campus_id')->nullable()->constrained('campuses')->cascadeOnDelete();
            }
        });
    }
};
