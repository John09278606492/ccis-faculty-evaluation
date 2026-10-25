<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Form;
use App\Models\FormSubmission;
use Illuminate\Support\Collection;

/** One complete-response policy and explicit score units for every report. */
class EvaluationScoring
{
    public function instrument(CourseOffering $assignment): string
    {
        $assignment->loadMissing('evaluationForm');
        $type = $assignment->evaluation_type_code;

        return $type === 'SEF' && ! $assignment->evaluationForm?->isSupervisorInstrument() ? 'OTHER' : $type;
    }

    public function response(Form $form, FormSubmission $submission): ?array
    {
        if ($submission->instrument_snapshot && class_exists(EvaluationInstrument::class)) {
            $form = app(EvaluationInstrument::class)->forSubmission($submission);
        }
        $form->loadMissing('fields');
        $fields = $form->fields->whereIn('type', ['rating', 'likert_scale'])->values();
        $scale = collect($form->rating_scale ?: [['value' => 5], ['value' => 1]]);
        $max = (float) $scale->max('value');
        $min = (float) $scale->min('value');
        if ($fields->isEmpty() || $max <= 0) {
            return null;
        }
        $values = [];
        foreach ($fields as $field) {
            $value = $submission->data[$field->name] ?? null;
            if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
                return null;
            }
            $values[$field->name] = (float) $value;
        }
        $raw = array_sum($values);
        $maximum = $max * $fields->count();
        $percentage = $raw / $maximum * 100;
        $config = $form->scoring_config ?? [];
        if (($config['overall_method'] ?? null) === 'weighted_average' && ! empty($config['section_weights'])) {
            $weights = collect($config['section_weights'])->pluck('weight', 'section_name');
            $weighted = $weightTotal = 0;
            foreach ($fields->groupBy(fn ($field) => $field->section ?: 'General') as $name => $group) {
                $weight = (float) ($weights[$name] ?? 0);
                if ($weight <= 0) {
                    continue;
                }
                $sectionRaw = $group->sum(fn ($field) => $values[$field->name]);
                $weighted += ($sectionRaw / ($group->count() * $max) * 100) * $weight;
                $weightTotal += $weight;
            }
            if ($weightTotal > 0) {
                $percentage = $weighted / $weightTotal;
            }
        }

        return ['raw_total' => $raw, 'maximum' => $maximum, 'percentage' => $percentage, 'mean_5' => $percentage / 20,
            'native_mean' => $raw / $fields->count(), 'scale_maximum' => $max, 'values' => $values];
    }

    public function assignment(CourseOffering $assignment, ?Collection $submissions = null): array
    {
        $assignment->loadMissing('evaluationForm.fields');
        $form = $assignment->evaluationForm;
        $submissions ??= $assignment->formSubmissions()->where('form_id', $assignment->form_id)->get();
        $submissions = $submissions->where('form_id', $assignment->form_id);
        $scored = $submissions->map(fn ($submission) => $form ? $this->response($form, $submission) : null)->filter()->values();
        $fields = $form?->fields->whereIn('type', ['rating', 'likert_scale']) ?? collect();
        $maxScale = (float) collect($form?->rating_scale ?: [['value' => 5]])->max('value');
        $fieldRows = $fields->map(function ($field) use ($scored, $maxScale) {
            $values = $scored->map(fn ($row) => $row['values'][$field->name]);

            return ['name' => $field->name, 'label' => $field->question_text, 'section' => $field->section ?: 'General',
                'avg' => $values->isNotEmpty() ? $values->avg() / $maxScale * 5 : null,
                'native_avg' => $values->avg(), 'count' => $values->count(), 'min' => $values->min(), 'max' => $values->max()];
        })->values();

        return ['instrument' => $this->instrument($assignment), 'count' => $scored->count(), 'submission_count' => $submissions->count(),
            'incomplete_count' => $submissions->count() - $scored->count(), 'mean_5' => $scored->avg('mean_5'),
            'percentage' => $scored->avg('percentage'), 'mean_total' => $scored->avg('raw_total'),
            'maximum' => $fields->count() * $maxScale, 'scale_maximum' => $maxScale, 'fields' => $fieldRows,
            'sections' => $fieldRows->groupBy('section')->map(fn ($rows) => $rows->avg('avg'))->all()];
    }

    public function instruments(Collection $rows): array
    {
        return $rows->groupBy('instrument')->map(function ($group) {
            $count = $group->sum('count');

            return ['count' => $count, 'mean_5' => $count ? $group->sum(fn ($row) => $row['mean_5'] * $row['count']) / $count : null,
                'percentage' => $count ? $group->sum(fn ($row) => $row['percentage'] * $row['count']) / $count : null];
        })->all();
    }
}
