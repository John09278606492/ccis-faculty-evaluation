<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds team_id (campus_id) to roles table for campus-scoped roles.
     * Note: We only add to roles table, not pivot tables, as we scope roles not individual assignments.
     */
    public function up(): void
    {
        // Add team_id to roles table
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('team_id')->nullable()->after('guard_name');
            $table->foreign('team_id')
                ->references('id')
                ->on('campuses')
                ->onDelete('cascade');

            // Add index for faster queries
            $table->index('team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropIndex(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
