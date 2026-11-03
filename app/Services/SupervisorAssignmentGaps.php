<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupervisorAssignmentGaps
{
    public function classes(User $actor, Campus $campus, int $cycleId)
    {
        abort_unless(app(DepartmentSupervisorAssignments::class)->allowed($actor, $campus), 403);
        $cycle = app(EvaluationCycles::class)->query($actor, $campus)->whereIn('status', ['draft', 'published'])->findOrFail($cycleId);

        return app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->where('evaluation_cycle_id', $cycle->id)->whereNotNull('course_id')->where('is_active', true)
            ->with(['course' => fn ($q) => $q->withoutGlobalScope('assigned_data'), 'evaluationForm', 'evaluatee' => fn ($q) => $q->withoutGlobalScope('assigned_data')])
            ->get()->reject(fn ($c) => $c->evaluationForm?->isSupervisorInstrument());
    }

    public function gaps(User $actor, Campus $campus, int $cycleId): array
    {
        $classes = $this->classes($actor, $campus, $cycleId);
        $pivots = DB::table('course_offering_supervisor')->whereIn('course_offering_id', $classes->modelKeys())->get(['course_offering_id', 'user_id']);
        $assigned = $pivots->pluck('course_offering_id')->flip();
        $department = app(EvaluationCycles::class)->query($actor, $campus)->with('department')->findOrFail($cycleId)->department;

        return $classes->reject(fn ($c) => $assigned->has($c->id))->groupBy('user_id')->map(function ($group) use ($department, $classes, $pivots) {
            $class = $group->first();
            $sets = $pivots->whereIn('course_offering_id', $classes->where('user_id', $class->user_id)->modelKeys())->groupBy('course_offering_id')
                ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->unique(fn ($ids) => implode(',', $ids));
            $ids = $sets->count() === 1 ? $sets->first() : [];
            if ($sets->isEmpty() && $department->supervisor_id && (int) $department->supervisor_id !== (int) $class->user_id) {
                $ids = [(int) $department->supervisor_id];
            }
            if ($ids && app(FacultySupervisorEvaluators::class)->candidates($class)->whereKey($ids)->count() !== count($ids)) {
                $ids = [];
            }

            return ['faculty_id' => $class->user_id, 'faculty_name' => $class->evaluatee?->name, 'class_count' => $group->count(), 'class_id' => $class->id,
                'supervisor_ids' => $ids, 'suggested' => ! empty($ids)];
        })->values()->all();
    }

    public function options(User $actor, Campus $campus, int $cycleId, int $facultyId): array
    {
        $class = $this->classes($actor, $campus, $cycleId)->firstWhere('user_id', $facultyId);
        abort_unless($class, 403);

        return app(FacultySupervisorEvaluators::class)->candidates($class)->pluck('name', 'id')->all();
    }

    private function validate(User $actor, Campus $campus, array $payload): array
    {
        $classes = $this->classes($actor, $campus, (int) $payload['cycle_id']);
        $selections = [];
        foreach ($payload['faculty_supervisors'] ?? [] as $entry) {
            $facultyId = (int) ($entry['faculty_id'] ?? 0);
            $class = $classes->firstWhere('user_id', $facultyId);
            $ids = array_values(array_unique(array_map('intval', $entry['supervisor_ids'] ?? [])));
            if (! $class || isset($selections[$facultyId]) || ! $ids || app(FacultySupervisorEvaluators::class)->candidates($class)->whereKey($ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['faculty_supervisors' => 'Choose valid same-campus supervisors for each listed faculty. Self-evaluation and duplicate faculty entries are not allowed.']);
            }
            $selections[$facultyId] = $ids;
        }
        if (! $selections) {
            throw ValidationException::withMessages(['faculty_supervisors' => 'Select at least one faculty supervisor assignment.']);
        }

        return [$classes, $selections];
    }

    public function enqueue(User $actor, Campus $campus, int $cycleId, array $selections)
    {
        $payload = ['cycle_id' => $cycleId, 'faculty_supervisors' => $selections];
        $this->validate($actor, $campus, $payload);

        return app(BackgroundTasks::class)->enqueue($actor, $campus, 'supervisor-resolution', 'Assign supervisors by faculty', $payload);
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
            [$classes, $selections] = $this->validate($actor, $campus, $payload);
            $assigned = $preserved = 0;
            foreach ($classes as $class) {
                if (! isset($selections[$class->user_id])) {
                    continue;
                }
                if (DB::table('course_offering_supervisor')->where('course_offering_id', $class->id)->exists()) {
                    $preserved++;

                    continue;
                }
                app(FacultySupervisorEvaluators::class)->save($actor, $class, $selections[$class->user_id]);
                $assigned++;
            }
            ClassroomUpdates::panel('setup');

            return ['assigned_classes' => $assigned, 'preserved_classes' => $preserved,
                'message' => $assigned.' classes received the selected supervisors. '.$preserved.' existing class choices were preserved.'];
        });
    }
}
