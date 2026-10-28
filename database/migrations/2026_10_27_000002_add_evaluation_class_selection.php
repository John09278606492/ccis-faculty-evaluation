<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_cycles', fn (Blueprint $table) => $table->json('class_selection')->nullable());
    }

    public function down(): void
    {
        Schema::table('evaluation_cycles', fn (Blueprint $table) => $table->dropColumn('class_selection'));
    }
};
