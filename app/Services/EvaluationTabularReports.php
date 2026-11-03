<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;

class EvaluationTabularReports
{
    public function build(User $actor, CourseOffering $anchor, array $filters = []): Collection
    {
        $anchor->loadMissing('semester.schoolYear.campus', 'evaluatee.instructorDepartment', 'course');
        $campus = $anchor->campus;
        $personal = $actor->id === $anchor->user_id;
        abort_unless(! $actor->isStudent() && $campus && $actor->canAccessTenant($campus)
            && ($actor->can('page_EvaluationResults') || ($personal && $actor->can('page_MyEvaluationResults'))), 403);
        abort_unless(app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->whereKey($anchor->id)->exists()
            && EvaluationResultsRelease::canSeeScores($actor, $anchor), 403);
        if ($filters['department'] ?? null) {
            abort_unless(app(AssignedDataAccess::class)->apply(Department::query(), $actor, $campus)->whereKey($filters['department'])->exists(), 403);
        }
        $context = ['campus_id' => $campus->id, 'semester_id' => $anchor->semester_id,
            'department_id' => $filters['department'] ?? null, 'form_id' => $filters['form'] ?? null,
            'evaluation_cycle_id' => $filters['cycle'] ?? null, 'evaluation_type' => $filters['instrument'] ?? null];
        $assignments = app(AssignedDataAccess::class)->apply(app(ReportService::class)->offerings($context), $actor, $campus)
            ->where('user_id', $anchor->user_id)->with(['evaluationForm.fields', 'formSubmissions', 'course.department', 'teachingDepartment'])
            ->orderBy('id')->get()->filter(fn ($assignment) => ! $personal || EvaluationResultsRelease::isReleased($assignment));
        $reports = collect();
        foreach ($assignments as $assignment) {
            if (! $assignment->evaluationForm) {
                continue;
            }
            $submissions = $assignment->formSubmissions->where('form_id', $assignment->form_id)->sortBy('id')->values();
            foreach ($submissions as $submission) {
                // Legacy responses have no snapshot. Reuse the eagerly loaded instrument.
                $submission->setRelation('form', $assignment->evaluationForm);
            }
            $numbers = $submissions->mapWithKeys(fn ($submission, $index) => [$submission->id => $index + 1])->all();
            $current = EvaluationInstrument::snapshot($assignment->evaluationForm);
            // Never merge different historical question sets into a single tally table.
            $groups = $submissions->groupBy(fn ($submission) => hash('sha256', json_encode($submission->instrument_snapshot ?: $current)));
            if ($groups->isEmpty()) {
                $groups = collect([collect()]);
            }
            foreach ($groups->values() as $index => $group) {
                $form = $group->isNotEmpty() ? EvaluationInstrument::forSubmission($group->first()) : $assignment->evaluationForm;
                $version = clone $assignment;
                $version->setRelation('evaluationForm', $form);
                $score = app(EvaluationScoring::class)->assignment($version, $group);
                $reports->push(['assignment' => $assignment, 'form' => $form, 'instrument' => $version->evaluation_type_label,
                    'revision' => $groups->count() > 1 ? $index + 1 : null, 'score' => $score,
                    'tally' => app(EvaluationTally::class)->build($version, $group, $numbers)]);
            }
        }

        return $reports;
    }
}
