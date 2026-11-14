<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acknowledgement_recipients', function (Blueprint $table) {
            // Set when the instructor takes a student off a receipt (for example, the student stopped attending).
            // The row stays so the student is not added back automatically and can be restored.
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acknowledgement_recipients', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
