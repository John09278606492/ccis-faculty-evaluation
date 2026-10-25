<?php

namespace App\Services;

use App\Models\CourseOffering;
use Illuminate\Support\Collection;

class EvaluationTally
{
    public function build(CourseOffering $assignment, Collection $submissions, array $numbers = []): array
    {
        $assignment->loadMissing('evaluationForm.fields');
        $form = $assignment->evaluationForm;
        $fields = $form?->fields->whereIn('type', ['likert_scale', 'rating'])->values() ?? collect();
        $maximum = $fields->count() * (collect($form?->rating_scale ?? [['value' => 5]])->max('value') ?? 5);
        $scale = collect($form?->rating_scale ?: [['value' => 5], ['value' => 1]]);
        $minimum = (float) $scale->min('value');
        $scaleMaximum = (float) $scale->max('value');
        $prefix = match ($assignment->evaluation_type_code) {
            'SET' => 'Student', 'SEF' => 'Supervisor', 'PEER', 'SELF' => 'Faculty', default => 'Evaluator'
        };
        $rows = [];
        foreach ($submissions->where('form_id', $assignment->form_id)->sortBy('id')->values() as $index => $submission) {
            $values = $fields->map(function ($field) use ($submission, $minimum, $scaleMaximum) {
                $value = $submission->data[$field->name] ?? null;

                return is_numeric($value) && (float) $value >= $minimum && (float) $value <= $scaleMaximum ? (float) $value : null;
            });
            $score = $form ? app(EvaluationScoring::class)->response($form, $submission) : null;
            $complete = $score !== null && $fields->isNotEmpty() && ! $values->contains(null);
            $total = $complete ? $values->sum() : null;
            $rows[] = ['label' => $prefix === 'Supervisor' ? 'Supervisor' : $prefix.' '.($numbers[$submission->id] ?? $index + 1),
                'values' => $values->all(), 'total' => $total, 'average' => $total !== null ? $total / $fields->count() : null,
                'percentage' => $complete ? $score['percentage'] : null];
        }
        $completeRows = collect($rows)->whereNotNull('total');
        $questionTotals = $fields->keys()->map(fn ($index) => $completeRows->isNotEmpty() ? $completeRows->sum(fn ($row) => $row['values'][$index]) : null)->all();
        $questionAverages = array_map(fn ($total) => $total !== null ? $total / $completeRows->count() : null, $questionTotals);

        return ['questions' => $fields->map(fn ($field, $index) => ['number' => $index + 1, 'label' => $field->question_text])->all(), 'rows' => $rows, 'maximum' => $maximum, 'scale_maximum' => $scaleMaximum,
            'summary' => ['complete' => $completeRows->count(), 'incomplete' => count($rows) - $completeRows->count(), 'question_totals' => $questionTotals,
                'question_averages' => $questionAverages, 'grand_total' => $completeRows->isNotEmpty() ? $completeRows->sum('total') : null,
                'mean_total' => $completeRows->avg('total'), 'mean_average' => $completeRows->avg('average'), 'mean_percentage' => $completeRows->avg('percentage')]];
    }
}
