<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->json('field_columns')->nullable()->after('scoring_config');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->json('column_values')->nullable()->after('help_text');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('field_columns');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('column_values');
        });
    }
};
