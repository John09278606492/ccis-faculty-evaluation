<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Add middle_name column for all users.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add middle_name after first_name
            $table->string('middle_name', 100)->nullable()->after('first_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('middle_name');
        });
    }
};
