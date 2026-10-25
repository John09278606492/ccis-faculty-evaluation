<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentDeletion
{
    public const NOTICE = 'Remove this student from active student lists and disable sign-in? Submitted evaluations, ratings, comments, printable copies and historical report counts will be kept. The student ID remains reserved for this record.';

    public function allowed(User $actor, Campus $campus, User $student): bool
    {
        if ($actor->trashed() || $actor->isStudent() || $student->trashed() || $actor->id === $student->id
            || ! $actor->canAccessTenant($campus) || ! $actor->can('view_academic_students')
            || ! $actor->can('manage_academic_students') || ! $actor->can('delete_academic_students')) {
            return false;
        }
        $departments = app(AssignedDataAccess::class)->departmentIds($actor, $campus);

        // Enrolment in another department grants visibility, not ownership of the student account.
        return ($departments === null || in_array($student->student_department_id, $departments, true))
            && ($actor->isSuperAdmin() || $student->campuses()->count() === 1)
            && app(AcademicStudents::class)->students($actor, $campus)->whereKey($student->id)->exists()
            && app(AcademicStudents::class)->isSafeStudentTarget($student);
    }

    /** Validate the whole selection before changing anything. Never detach historical records. */
    public function delete(User $actor, Campus $campus, array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        abort_unless($ids !== [] && count($ids) <= 5000, 422);

        return DB::transaction(function () use ($actor, $campus, $ids) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $students = app(AcademicStudents::class)->students($actor, $campus)->whereKey($ids)
                ->with('roles.permissions', 'permissions')->lockForUpdate()->get();
            abort_unless($students->count() === count($ids), 403);
            foreach ($students as $student) {
                abort_unless($this->allowed($actor, $campus, $student), 403, 'You may delete only ordinary student accounts in your assigned campus and department.');
            }
            foreach ($students as $student) {
                $student->forceFill(['remember_token' => null])->save();
                $student->tokens()->delete();
                DB::table('student_sign_in_requests')->where('user_id', $student->id)->delete();
                $student->delete();
                app(UserAccessAdministration::class)->audit($actor, $student, $campus, 'student.deleted', ['history_preserved' => true]);
            }

            return $students->count();
        });
    }
}
