<?php

namespace App\Console\Commands;

use App\Models\AcademicSection;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\StudentSectionMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates (or reuses) a teaching class for one section, taught by an existing
 * instructor, with the section's students on its roster, as the Teaching
 * Classes page's "Assign by section" does. Used to prepare a class for an imported exam.
 */
class CreateSectionClass extends Command
{
    protected $signature = 'classroom:create-section-class {--section= : Section name, e.g. "BSIT 3C"} {--course-code=} {--course-name=} {--instructor= : Instructor user id}';

    protected $description = 'Create a teaching class for a section and assign its students';

    public function handle(): int
    {
        $instructor = User::withoutGlobalScopes()->find($this->option('instructor'));
        $section = AcademicSection::withoutGlobalScopes()->where('name', $this->option('section'))->where('is_active', true)->latest('semester_id')->first();
        if (! $instructor || ! $section || blank($this->option('course-code'))) {
            $this->error('Give an existing --instructor id, an active --section name and a --course-code.');

            return self::FAILURE;
        }
        $course = Course::withoutGlobalScopes()->where('department_id', $section->department_id)->where('code', $this->option('course-code'))->first()
            ?? Course::create(['department_id' => $section->department_id, 'code' => $this->option('course-code'), 'name' => $this->option('course-name') ?: $this->option('course-code'), 'units' => 3, 'is_active' => true]);
        $group = $section->department_id.'~'.$section->semester_id.'~'.rawurlencode($section->name);
        $studentIds = StudentSectionMembership::where('department_id', $section->department_id)->where('semester_id', $section->semester_id)
            ->where('section', $section->name)->whereIn('user_id', DB::table('users')->whereNull('deleted_at')->select('id'))->pluck('user_id')->unique()->values();

        $offering = DB::transaction(function () use ($section, $course, $instructor, $group, $studentIds) {
            $offering = CourseOffering::withoutGlobalScopes()->firstOrCreate(
                ['semester_id' => $section->semester_id, 'course_id' => $course->id, 'user_id' => $instructor->id, 'section' => $section->name],
                ['is_active' => true, 'teaching_department_id' => $course->department_id],
            );
            $offering->students()->syncWithoutDetaching($studentIds->mapWithKeys(fn ($id) => [$id => ['enrolled_at' => now()]])->all());
            // The same record the Teaching Classes "Assign by section" action keeps.
            $offering->update(['evaluator_selection' => ['sections' => [$group], 'include' => [], 'exclude' => [], 'mode' => 'replace',
                'count' => $offering->students()->count(), 'saved_at' => now()->toIso8601String(), 'source' => 'sections_v1']]);

            return $offering;
        });
        $students = $offering->students()->count();

        $this->info("Class id {$offering->id}: {$course->code} {$offering->section}, instructor {$instructor->first_name} {$instructor->last_name}, {$students} students.");

        return self::SUCCESS;
    }
}
