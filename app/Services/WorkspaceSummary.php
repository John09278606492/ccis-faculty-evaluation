<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Department;
use App\Models\User;

class WorkspaceSummary
{
    public function for(User $user, Campus $campus): array
    {
        $period = AcademicPeriod::current($campus);
        if ($user->isStudent()) {
            $p = app(StudentEvaluationProgress::class)->summary($user, $campus, $period);

            return ['student' => true, 'cards' => [
                ['label' => 'Ready to evaluate', 'value' => $p['open_pending'], 'hint' => 'Open assignments awaiting your response'],
                ['label' => 'Completed', 'value' => $p['completed'], 'hint' => 'Your submitted evaluations'],
                ['label' => 'Not yet open', 'value' => $p['not_open'], 'hint' => 'Assignments scheduled for later'],
            ], 'warnings' => []];
        }
        $scope = app(AssignedDataAccess::class);
        $departments = $scope->apply(Department::query(), $user, $campus)->where('is_active', true)->with(['supervisor', 'chairperson'])->get();
        $pending = app(SupervisorEvaluationAssignments::class)->forViewer($user, $campus)
            ->with(['evaluationCycle', 'evaluationForm'])->get()->filter(fn ($a) => $a->isEvaluationAvailable()
                && ! $a->formSubmissions()->withoutGlobalScope('assigned_data')->where('form_id', $a->form_id)
                    ->where('submission_fingerprint', app(EvaluationSubmissionIdentity::class)->fingerprint($a, request(), $user))->exists())->count();
        $cycles = app(EvaluationCycles::class)->query($user, $campus)->where('semester_id', $period);
        $draft = (clone $cycles)->where('status', 'draft')->count();
        $published = (clone $cycles)->where('status', 'published')->count();

        return ['student' => false, 'cards' => [
            ['label' => 'My supervisor tasks', 'value' => $pending, 'hint' => 'Annex B evaluations waiting for you'],
            ['label' => 'Published evaluations', 'value' => $published, 'hint' => 'Scheduled or collecting responses this academic period'],
            ['label' => 'Evaluations being prepared', 'value' => $draft, 'hint' => 'Open Evaluation Setup to review and publish'],
        ], 'warnings' => array_values(array_filter([
            $departments->isEmpty() ? 'No department access is assigned. Ask an administrator to review Access & Assignments.' : null,
        ]))];
    }
}
