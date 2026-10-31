<?php

namespace App\Services;

use App\Models\BackgroundTask;
use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** An explicit bulk shortcut; each teaching class retains its own delegation. */
class DepartmentSupervisorAssignments
{
    public function allowed(User $actor, Campus $campus): bool
    {
        return app(EvaluationCycles::class)->allowed($actor, $campus)
            && (! filament()->getTenant() || filament()->getTenant()->id === $campus->id);
    }

    public function department(User $actor, Campus $campus, int $id): Department
    {
        abort_unless($this->allowed($actor, $campus), 403);

        return app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->where('is_active', true)->findOrFail($id);
    }

    public function enqueue(User $actor, Campus $campus, int $departmentId, int $semesterId, ?int $cycleId = null): BackgroundTask
    {
        $department = $this->department($actor, $campus, $departmentId);
        $payload = ['department_id' => $departmentId, 'semester_id' => $semesterId, 'cycle_id' => $cycleId,
            'supervisor_id' => (int) $department->supervisor_id];
        $this->validate($actor, $campus, $payload);

        return app(BackgroundTasks::class)->enqueue($actor, $campus, 'supervisor-bulk', 'Fill missing supervisors — '.$department->code, $payload);
    }

    private function validate(User $actor, Campus $campus, array $payload): Department
    {
        $department = $this->department($actor, $campus, (int) $payload['department_id']);
        AcademicPeriod::validate($campus, (int) $payload['semester_id']);
        if (! $department->supervisor_id || (int) $department->supervisor_id !== (int) $payload['supervisor_id']) {
            throw ValidationException::withMessages(['supervisor_id' => 'Select and save the department supervisor first. If that selection changed, start the bulk assignment again.']);
        }
        if (! User::withoutGlobalScope('assigned_data')->whereKey($department->supervisor_id)->where('is_student', false)
            ->whereHas('roles')->whereHas('campuses', fn ($q) => $q->whereKey($campus->id))->exists()) {
            throw ValidationException::withMessages(['supervisor_id' => 'The department supervisor must be an active staff account in this campus.']);
        }
        if (! empty($payload['cycle_id'])) {
            abort_unless(app(EvaluationCycles::class)->query($actor, $campus)->whereKey($payload['cycle_id'])
                ->where('department_id', $department->id)->where('semester_id', $payload['semester_id'])->whereIn('status', ['draft', 'published'])->exists(), 403);
        }

        return $department;
    }

    public function apply(User $actor, Campus $campus, array $payload): array
    {
        return DB::transaction(function () use ($actor, $campus, $payload) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $actor = User::withoutGlobalScope('assigned_data')->findOrFail($actor->id);
            $department = $this->validate($actor, $campus, $payload);
            $classes = app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)
                ->where('semester_id', $payload['semester_id'])->where('is_active', true)->whereNotNull('course_id')
                ->where(fn ($q) => $q->where('teaching_department_id', $department->id)
                    ->orWhere(fn ($q) => $q->whereNull('teaching_department_id')->whereHas('course', fn ($c) => $c->where('department_id', $department->id))))
                ->when($payload['cycle_id'] ?? null, fn ($q, $id) => $q->where('evaluation_cycle_id', $id))
                ->where(fn ($q) => $q->whereNull('evaluation_cycle_id')->orWhereHas('evaluationCycle', fn ($c) => $c->whereIn('status', ['draft', 'published'])))
                ->with('evaluationForm')->get()->reject(fn ($c) => $c->evaluationForm?->isSupervisorInstrument());
            $assigned = $preserved = $self = 0;
            foreach ($classes as $class) {
                // Read the pivot directly: a supervisor from another department
                // must not disappear behind the current actor's user scope.
                if (DB::table('course_offering_supervisor')->where('course_offering_id', $class->id)->exists()) {
                    $preserved++;

                    continue;
                }
                if ((int) $class->user_id === (int) $department->supervisor_id) {
                    $self++;

                    continue;
                }
                app(FacultySupervisorEvaluators::class)->save($actor, $class, [(int) $department->supervisor_id]);
                $assigned++;
            }
            ClassroomUpdates::panel('setup');

            return ['assigned_classes' => $assigned, 'preserved_classes' => $preserved, 'self_evaluation_skipped' => $self,
                'message' => $assigned.' classes received the department supervisor. '.$preserved.' existing supervisor selections were kept. '
                    .$self.' self-evaluation cases were skipped; select another supervisor for those faculty.'];
        });
    }
}
