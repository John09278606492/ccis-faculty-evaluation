<?php

namespace App\Services;

use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\StudentSectionMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AcademicSections
{
    public function authorize(User $actor, Campus $campus, bool $write = false, bool $upload = false): void
    {
        abort_unless(! $actor->isStudent() && $actor->canAccessTenant($campus) && $actor->can('view_academic_students')
            && (! $write || $actor->can('manage_academic_students')) && (! $upload || $actor->can('import_academic_students')), 403);
    }

    public function query(User $actor, Campus $campus): Builder
    {
        return app(AssignedDataAccess::class)->apply(AcademicSection::query(), $actor, $campus);
    }

    public function section(User $actor, Campus $campus, int $id, bool $write = false, bool $upload = false): AcademicSection
    {
        $this->authorize($actor, $campus, $write, $upload);

        return $this->query($actor, $campus)->findOrFail($id);
    }

    public function save(User $actor, Campus $campus, array $data, ?AcademicSection $section = null): AcademicSection
    {
        $this->authorize($actor, $campus, true);
        if ($section) {
            $section = $this->section($actor, $campus, $section->id, true);
        }
        $data['name'] = TeachingClassRosterImports::section((string) ($data['name'] ?? ''));
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:50', Rule::unique('academic_sections', 'name')->ignore($section?->id)
                ->where('department_id', $data['department_id'] ?? null)->where('semester_id', $data['semester_id'] ?? null)],
            'department_id' => ['required', Rule::in(app(AcademicStudents::class)->departments($actor, $campus)->pluck('id'))],
            'semester_id' => ['required', Rule::in(array_keys(AcademicPeriod::options($campus)))], 'is_active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($section, $data) {
            if ($section) {
                $members = StudentSectionMembership::where('academic_section_id', $section->id);
                if ((clone $members)->exists() && ((int) $data['semester_id'] !== $section->semester_id || (int) $data['department_id'] !== $section->department_id)) {
                    throw ValidationException::withMessages(['semester_id' => 'Create a new section for a different department or academic period. Existing section students and evaluation history are preserved.']);
                }
                $oldGroup = $section->department_id.'~'.$section->semester_id.'~'.rawurlencode($section->name);
                $section->update($data);
                $members->update(['department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name]);
                $newGroup = $section->department_id.'~'.$section->semester_id.'~'.rawurlencode($section->name);
                if ($oldGroup !== $newGroup) {
                    // Rename references, preserving the already assigned class rosters.
                    DB::table('course_offerings')->where('semester_id', $section->semester_id)->whereJsonContains('evaluator_selection->sections', $oldGroup)->orderBy('id')->each(function ($class) use ($oldGroup, $newGroup) {
                        $selection = json_decode($class->evaluator_selection, true);
                        $selection['sections'] = array_map(fn ($key) => $key === $oldGroup ? $newGroup : $key, $selection['sections']);
                        DB::table('course_offerings')->where('id', $class->id)->update(['evaluator_selection' => json_encode($selection)]);
                    });
                }

                return $section;
            }

            return AcademicSection::create($data);
        });
    }

    public function eligible(User $actor, Campus $campus, AcademicSection $section): Builder
    {
        return app(AcademicStudents::class)->students($actor, $campus)->where('student_department_id', $section->department_id)
            ->where(fn ($q) => $q->whereHas('academicRecords', fn ($q) => $q->where('semester_id', $section->semester_id))
                ->orWhereHas('studentSectionMemberships', fn ($q) => $q->where('semester_id', $section->semester_id)));
    }

    public function add(User $actor, Campus $campus, AcademicSection $section, array $ids): int
    {
        $section = $this->section($actor, $campus, $section->id, true);
        abort_unless($section->is_active, 422, 'Activate this section before adding students.');
        $ids = array_unique(array_map('intval', $ids));
        $students = $this->eligible($actor, $campus, $section)->whereKey($ids)->with('roles.permissions', 'permissions')->get();
        abort_unless($students->count() === count($ids) && $students->every(fn ($s) => app(AcademicStudents::class)->isSafeStudentTarget($s)), 403);

        return DB::transaction(function () use ($section, $students) {
            $added = 0;
            foreach ($students as $student) {
                $member = StudentSectionMembership::firstOrCreate(['user_id' => $student->id, 'department_id' => $section->department_id,
                    'semester_id' => $section->semester_id, 'section' => $section->name], ['academic_section_id' => $section->id]);
                $added += (int) $member->wasRecentlyCreated;
            }

            return $added;
        });
    }

    /** True when no student belongs to the section, by link or by its department/period/name. */
    public function isEmpty(AcademicSection $section): bool
    {
        return ! StudentSectionMembership::where('academic_section_id', $section->id)
            ->orWhere(fn ($q) => $q->where('department_id', $section->department_id)->where('semester_id', $section->semester_id)->where('section', $section->name))
            ->exists();
    }

    /** Empty sections only: a leftover duplicate makes uploads and section matching ambiguous. */
    public function delete(User $actor, Campus $campus, AcademicSection $section): void
    {
        DB::transaction(function () use ($actor, $campus, $section) {
            $section = $this->section($actor, $campus, $section->id, true);
            if (! $this->isEmpty($section)) {
                throw ValidationException::withMessages(['section' => 'Remove the students from '.$section->name.' before deleting it.']);
            }
            $section->delete();
        });
    }

    public function remove(User $actor, Campus $campus, AcademicSection $section, array $ids): void
    {
        $section = $this->section($actor, $campus, $section->id, true);
        StudentSectionMembership::where('academic_section_id', $section->id)->whereIn('user_id', $ids)->get()->each->delete();
    }
}
