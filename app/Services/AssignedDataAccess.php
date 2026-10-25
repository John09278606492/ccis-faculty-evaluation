<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Form;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Data scope supplements permissions; permissions never expand assigned departments. */
class AssignedDataAccess
{
    public ?User $actor = null;

    public function departmentIds(User $actor, Campus $campus): ?array
    {
        if (! $actor->canAccessTenant($campus)) {
            return [];
        }
        if ($actor->isSuperAdmin()) {
            return null;
        }
        if (Schema::hasTable('user_campus_access') && DB::table('user_campus_access')->where('user_id', $actor->id)->where('campus_id', $campus->id)->where('scope', 'campus')->exists()) {
            return null;
        }
        $ids = DB::table('departments')->where('campus_id', $campus->id)->where('is_active', true)
            ->where(fn ($q) => $q->where('supervisor_id', $actor->id)->orWhere('chairperson_id', $actor->id)
                ->orWhere('id', $actor->instructor_department_id)
                ->when(Schema::hasTable('user_department_access'), fn ($q) => $q->orWhereIn('id', DB::table('user_department_access')->select('department_id')->where('user_id', $actor->id))))->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids !== []) {
            return $ids;
        }

        // Permissions and profile removal never create campus-wide authority.
        return [];
    }

    private function departments(Campus $campus, ?array $ids)
    {
        return DB::table('departments')->select('id')->where('campus_id', $campus->id)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
    }

    private function semesters(Campus $campus)
    {
        return DB::table('semesters')->select('id')->whereIn('school_year_id', DB::table('school_years')->select('id')->where('campus_id', $campus->id));
    }

    private function offerings(Campus $campus, ?array $ids)
    {
        $departments = $this->departments($campus, $ids);

        $query = DB::table('course_offerings')->select('id')->whereIn('semester_id', $this->semesters($campus));
        if ($ids === null) {
            return $query;
        }
        $fallback = fn ($q) => $q->whereIn('course_id', DB::table('courses')->select('id')->whereIn('department_id', $departments))
            ->orWhere(fn ($q) => $q->whereNull('course_id')->whereIn('user_id', DB::table('users')->select('id')->whereIn('instructor_department_id', $departments)));
        if (Schema::hasColumn('course_offerings', 'teaching_department_id')) {
            return $query->where(fn ($q) => $q->whereIn('teaching_department_id', $departments)
                ->orWhere(fn ($q) => $q->whereNull('teaching_department_id')->where($fallback)));
        }

        return $query->where($fallback);
    }

    public function managesOffering(User $actor, CourseOffering $offering): bool
    {
        $offering->loadMissing('semester.schoolYear.campus');
        $campus = $offering->campus;

        return $campus && $actor->canAccessTenant($campus)
            && $this->offerings($campus, $this->departmentIds($actor, $campus))->where('id', $offering->id)->exists();
    }

    public function apply(Builder $query, User $actor, Campus $campus): Builder
    {
        if (! $actor->canAccessTenant($campus)) {
            return $query->whereRaw('1 = 0');
        }
        $ids = $this->departmentIds($actor, $campus);
        $table = $query->getModel()->getTable();
        $delegated = fn () => app(FacultySupervisorEvaluators::class)->taskIds($actor, $campus);
        if ($ids === [] && $table !== 'users'
            && (! in_array($table, ['course_offerings', 'forms', 'form_fields', 'semesters', 'school_years'], true) || ! $delegated()->exists())) {
            return $query->whereRaw('1 = 0');
        }
        $departments = $this->departments($campus, $ids);
        $offerings = $this->offerings($campus, $ids);
        switch ($table) {
            case 'academic_sections':
                $query->whereIn('academic_sections.department_id', $departments)->whereIn('academic_sections.semester_id', $this->semesters($campus));
                break;
            case 'departments':
                $query->whereIn('departments.id', $departments);
                break;
            case 'courses':
                $query->whereIn('courses.department_id', $departments);
                break;
            case 'rating_criteria':
                $query->where('rating_criteria.campus_id', $campus->id);
                break;
            case 'form_fields':
                $forms = $this->apply(Form::query()->withoutGlobalScope('assigned_data'), $actor, $campus)->select('forms.id');
                $query->whereIn('form_fields.form_id', $forms->toBase());
                break;
            case 'school_years':
                $query->where('school_years.campus_id', $campus->id);
                break;
            case 'semesters':
                $query->whereIn('semesters.id', $this->semesters($campus));
                break;
            case 'course_offerings':
                $query->where(fn ($q) => $q->whereIn('course_offerings.id', $offerings)->orWhereIn('course_offerings.id', $delegated()));
                break;
            case 'form_submissions':
            case 'ratings':
            case 'quizzes':
                $query->whereIn($table.'.course_offering_id', $offerings);
                break;
            case 'class_record_sheets':
                $query->where(fn ($q) => $q->whereIn('class_record_sheets.course_offering_id', $offerings)
                    ->orWhere(fn ($q) => $q->whereNull('class_record_sheets.course_offering_id')->whereIn('class_record_upload_id', DB::table('class_record_uploads')->select('id')->where('uploaded_by', $actor->id))));
                break;
            case 'posts':
            case 'contacts':
                $query->where($table.'.campus_id', $campus->id);
                break;
            case 'quiz_questions':
            case 'quiz_attempts':
                $query->whereIn($table.'.quiz_id', DB::table('quizzes')->select('id')->whereIn('course_offering_id', $offerings));
                break;
            case 'forms':
                $query->whereIn('forms.id', DB::table('campus_form')->select('form_id')->where('campus_id', $campus->id));
                if ($ids !== null) {
                    $query->where(fn ($q) => $q->whereIn('forms.id', DB::table('department_form')->select('form_id')->whereIn('department_id', $departments))
                        ->orWhereIn('forms.id', DB::table('course_offerings')->select('form_id')->whereIn('id', $delegated()))
                        ->when($ids !== [], fn ($q) => $q->orWhereNotIn('forms.id', DB::table('department_form')->select('form_id'))));
                }
                break;
            case 'users':
                $query->whereIn('users.id', DB::table('campus_user')->select('user_id')->where('campus_id', $campus->id));
                if ($ids !== null) {
                    $query->where(fn ($q) => $q->where('users.id', $actor->id)->orWhereIn('users.instructor_department_id', $departments)
                        ->orWhereIn('users.student_department_id', $departments)
                        ->orWhereIn('users.id', DB::table('departments')->select('supervisor_id')->whereIn('id', $departments))
                        ->orWhereIn('users.id', DB::table('departments')->select('chairperson_id')->whereIn('id', $departments))
                        ->orWhereIn('users.id', DB::table('course_offering_student')->select('user_id')->whereIn('course_offering_id', $offerings))
                        ->orWhereIn('users.id', DB::table('course_offerings')->select('user_id')->whereIn('id', $offerings)));
                }
                break;
        }

        return $query;
    }
}
