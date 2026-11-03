<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Curricula reuse one subject code for different subjects (PROF ELEC 2 is
    // Web Systems for BSIT and Graphics for BSCS), so code + title is the subject.
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique(['department_id', 'code']);
            $table->unique(['department_id', 'code', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropUnique(['department_id', 'code', 'name']);
            $table->unique(['department_id', 'code']);
        });
    }
};
