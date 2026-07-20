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
        // Drop campus_id from posts table
        if (Schema::hasTable('posts')) {
            Schema::table('posts', function (Blueprint $table) {
                if (Schema::hasColumn('posts', 'campus_id')) {
                    $table->dropForeign(['campus_id']);
                    $table->dropIndex(['campus_id']);
                    $table->dropColumn('campus_id');
                }
            });
        }

        // Drop campus_id from contacts table
        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table) {
                if (Schema::hasColumn('contacts', 'campus_id')) {
                    $table->dropForeign(['campus_id']);
                    $table->dropIndex(['campus_id']);
                    $table->dropColumn('campus_id');
                }
            });
        }

        // Drop campus_id from books table
        if (Schema::hasTable('books')) {
            Schema::table('books', function (Blueprint $table) {
                if (Schema::hasColumn('books', 'campus_id')) {
                    $table->dropForeign(['campus_id']);
                    $table->dropIndex(['campus_id']);
                    $table->dropColumn('campus_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback if needed - add campus_id back
        if (Schema::hasTable('posts')) {
            Schema::table('posts', function (Blueprint $table) {
                if (! Schema::hasColumn('posts', 'campus_id')) {
                    $table->foreignId('campus_id')->nullable()->after('id')->constrained('campuses')->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table) {
                if (! Schema::hasColumn('contacts', 'campus_id')) {
                    $table->foreignId('campus_id')->nullable()->after('id')->constrained('campuses')->cascadeOnDelete();
                }
            });
        }

        if (Schema::hasTable('books')) {
            Schema::table('books', function (Blueprint $table) {
                if (! Schema::hasColumn('books', 'campus_id')) {
                    $table->foreignId('campus_id')->nullable()->after('id')->constrained('campuses')->cascadeOnDelete();
                }
            });
        }
    }
};
