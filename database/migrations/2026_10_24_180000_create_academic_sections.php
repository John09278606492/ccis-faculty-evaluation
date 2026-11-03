<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['department_id', 'semester_id', 'name'], 'academic_section_period_name_unique');
        });
        Schema::table('student_section_memberships', fn (Blueprint $table) => $table->foreignId('academic_section_id')->nullable()->constrained()->nullOnDelete());
        foreach (DB::table('student_section_memberships')->select('department_id', 'semester_id', 'section')->distinct()->get() as $group) {
            $id = DB::table('academic_sections')->insertGetId(['department_id' => $group->department_id, 'semester_id' => $group->semester_id,
                'name' => $group->section, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('student_section_memberships')->where('department_id', $group->department_id)->where('semester_id', $group->semester_id)->where('section', $group->section)->update(['academic_section_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('student_section_memberships', fn (Blueprint $table) => $table->dropConstrainedForeignId('academic_section_id'));
        Schema::dropIfExists('academic_sections');
    }
};
