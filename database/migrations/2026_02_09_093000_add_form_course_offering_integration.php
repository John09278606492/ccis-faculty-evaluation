<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration links Forms to Course Offerings for instructor evaluation.
     */
    public function up(): void
    {
        // Add form_id to course_offerings - which evaluation form to use
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->foreignId('form_id')->nullable()->after('is_active')->constrained()->nullOnDelete();
        });

        // Add course_offering_id to form_submissions - track which course was being evaluated
        Schema::table('form_submissions', function (Blueprint $table) {
            $table->foreignId('course_offering_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_offerings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('form_id');
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_offering_id');
        });
    }
};
