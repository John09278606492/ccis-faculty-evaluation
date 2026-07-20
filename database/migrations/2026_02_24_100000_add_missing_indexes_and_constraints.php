<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds missing database indexes and constraints for data integrity and performance.
 *
 * Addresses:
 * - Composite index on form_submissions for duplicate detection queries
 * - Unique constraint on form_fields to prevent duplicate field names per form
 */
return new class extends Migration
{
    public function up(): void
    {
        // #14: Add composite index for the duplicate submission check query
        // Used by: WHERE form_id = ? AND course_offering_id = ? AND user_id = ?
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->index(
                ['form_id', 'course_offering_id', 'user_id'],
                'form_submissions_lookup_index'
            );
        });

        // #15: Add unique constraint to prevent duplicate field names within the same form
        // This prevents data collisions when processing submissions (e.g., two fields named 'name')
        Schema::table('form_fields', function (Blueprint $table) {
            $table->unique(['form_id', 'name'], 'form_fields_form_id_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropIndex('form_submissions_lookup_index');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropUnique('form_fields_form_id_name_unique');
        });
    }
};
