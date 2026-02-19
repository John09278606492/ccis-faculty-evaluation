<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Create pivot table for many-to-many campus-form relationship
        Schema::create('campus_form', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['campus_id', 'form_id']);
        });

        // Migrate existing campus_id data to pivot table
        $forms = DB::table('forms')->whereNotNull('campus_id')->get();
        foreach ($forms as $form) {
            DB::table('campus_form')->insert([
                'campus_id' => $form->campus_id,
                'form_id' => $form->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Drop the old campus_id column
        Schema::table('forms', function (Blueprint $table) {
            $table->dropForeign(['campus_id']);
            $table->dropColumn('campus_id');
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->foreignId('campus_id')->nullable()->constrained()->nullOnDelete();
        });

        // Migrate data back - take first campus for each form
        $pivots = DB::table('campus_form')
            ->select('form_id', DB::raw('MIN(campus_id) as campus_id'))
            ->groupBy('form_id')
            ->get();

        foreach ($pivots as $pivot) {
            DB::table('forms')->where('id', $pivot->form_id)->update(['campus_id' => $pivot->campus_id]);
        }

        Schema::dropIfExists('campus_form');
    }
};
