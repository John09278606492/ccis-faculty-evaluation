<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add rating_scale configuration to forms.
     * Allows custom rating scales per form (e.g., 5-point, 4-point, 10-point).
     */
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->json('rating_scale')->nullable()->after('evaluator_roles');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('rating_scale');
        });
    }
};
