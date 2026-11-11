<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('advisory_tasks', function (Blueprint $table) {
            $table->foreignId('advising_semester_id')->nullable()->constrained('semesters')->restrictOnDelete();
        });

        // Preserve the former displayed term for existing tasks; advisers may correct it separately.
        DB::table('advisory_tasks')->update([
            'advising_semester_id' => DB::raw('(SELECT academic_sections.semester_id FROM advisory_classes JOIN academic_sections ON academic_sections.id = advisory_classes.academic_section_id WHERE advisory_classes.id = advisory_tasks.advisory_class_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('advisory_tasks', fn (Blueprint $table) => $table->dropConstrainedForeignId('advising_semester_id'));
    }
};
