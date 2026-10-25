<?php

namespace App\Console\Commands;

use App\Models\AcademicSection;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\User;
use App\Services\AcademicStudents;
use App\Services\TeachingClasses;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Creates (or reuses) a teaching class for one section, taught by an existing
 * instructor, with the section's students on its roster, the same way the
 * Teaching Classes page does it. Used to prepare a class for an imported exam.
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
        $campus = CourseOffering::withoutGlobalScopes()->where('user_id', $instructor->id)->latest('id')->first()?->campus;
        if (! $campus) {
            $this->error('The instructor has no class yet, so their campus is unknown.');

            return self::FAILURE;
        }

        Auth::setUser($instructor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($campus, isQuiet: true);

        $course = Course::withoutGlobalScopes()->where('department_id', $section->department_id)->where('code', $this->option('course-code'))->first()
            ?? Course::create(['department_id' => $section->department_id, 'code' => $this->option('course-code'), 'name' => $this->option('course-name') ?: $this->option('course-code'), 'units' => 3, 'is_active' => true]);
        $offering = CourseOffering::withoutGlobalScopes()->where(['semester_id' => $section->semester_id, 'course_id' => $course->id, 'user_id' => $instructor->id, 'section' => $section->name])->first()
            ?? app(TeachingClasses::class)->save($instructor, $campus, ['semester_id' => $section->semester_id, 'course_id' => $course->id, 'user_id' => $instructor->id, 'section' => $section->name, 'is_active' => true]);
        $group = $section->department_id.'~'.$section->semester_id.'~'.rawurlencode($section->name);
        $students = app(AcademicStudents::class)->assignSections($instructor, $offering, [$group]);

        $this->info("Class id {$offering->id}: {$course->code} {$offering->section}, instructor {$instructor->first_name} {$instructor->last_name}, {$students} students.");

        return self::SUCCESS;
    }
}
