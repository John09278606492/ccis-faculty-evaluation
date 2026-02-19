<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Adds section grouping and role restrictions to forms.
     */
    public function up(): void
    {
        // Add section field to form_fields for grouping criteria
        Schema::table('form_fields', function (Blueprint $table) {
            $table->string('section')->nullable()->after('form_id');
        });

        // Add evaluator_roles to forms - which roles can access this form
        Schema::table('forms', function (Blueprint $table) {
            $table->json('evaluator_roles')->nullable()->after('requires_login');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('evaluator_roles');
        });
    }
};
