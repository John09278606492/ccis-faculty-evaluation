<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EvaluationDraftPreparation
{
    public function prepare(User $actor, Campus $campus, int $semesterId, array $departmentIds): array
    {
        abort_unless(app(EvaluationCycles::class)->allowed($actor, $campus), 403);
        AcademicPeriod::validate($campus, $semesterId);
        $ids = array_values(array_unique(array_map('intval', $departmentIds)));
        $departments = app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->where('is_active', true)->whereKey($ids)->get();
        abort_unless($ids && $departments->count() === count($ids), 403);

        return DB::transaction(function () use ($actor, $campus, $semesterId, $departments) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('campuses')->where('id', $campus->id)->update(['id' => $campus->id]);
            } else {
                Campus::whereKey($campus->id)->lockForUpdate()->firstOrFail();
            }
            $results = [];
            $created = $existing = $skipped = 0;
            foreach ($departments as $department) {
                $current = app(EvaluationCycles::class)->query($actor, $campus)->where('department_id', $department->id)->where('semester_id', $semesterId)->whereIn('status', ['draft', 'published'])->latest('id')->first();
                if ($current) {
                    $existing++;
                    $results[] = ['department' => $department->code, 'cycle_id' => $current->id, 'status' => 'existing'];

                    continue;
                }
                $defaults = app(EvaluationSetupAutomation::class)->defaults($actor, $campus, $department->id, $semesterId);
                if (empty($defaults['student_form_id']) || empty($defaults['supervisor_form_id'])) {
                    $skipped++;
                    $results[] = ['department' => $department->code, 'status' => 'needs_forms', 'message' => 'Choose Annex A and Annex B once for this department; no form version was guessed.'];

                    continue;
                }
                $cycle = app(EvaluationCycles::class)->save($actor, $campus, [...$defaults, 'department_id' => $department->id, 'semester_id' => $semesterId,
                    'starts_at' => null, 'ends_at' => null, 'class_selection_mode' => 'prepared']);
                $created++;
                $results[] = ['department' => $department->code, 'cycle_id' => $cycle->id, 'status' => 'created', 'classes' => $cycle->assignments()->count()];
            }
            ClassroomUpdates::panel('setup');

            return ['created' => $created, 'existing' => $existing, 'skipped' => $skipped, 'departments' => $results,
                'message' => $created.' evaluation drafts prepared. '.$existing.' existing evaluations kept. '.$skipped.' departments need form selection. Review the drafts before opening.'];
        });
    }
}
