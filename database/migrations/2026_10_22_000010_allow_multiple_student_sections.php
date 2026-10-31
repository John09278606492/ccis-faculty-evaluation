<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->json('sections')->nullable());
        DB::table('users')->whereNotNull('section')->orderBy('id')->chunkById(500, function ($users) {
            foreach ($users as $user) {
                $sections = array_values(array_unique(array_filter(array_map(fn ($value) => strtoupper(preg_replace('/\s+/', ' ', trim($value))), explode('|', $user->section)))));
                DB::table('users')->where('id', $user->id)->update(['sections' => json_encode($sections)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('sections'));
    }
};
