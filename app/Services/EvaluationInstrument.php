<?php

namespace App\Services;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;

class EvaluationInstrument
{
    public static function snapshot(Form $form): array
    {
        $form->loadMissing('fields');

        return ['form' => $form->only(['id', 'name', 'description', 'version', 'rating_scale', 'scoring_config', 'field_columns', 'settings']),
            'fields' => $form->fields->map(fn ($field) => $field->getAttributes())->all()];
    }

    public static function forSubmission(FormSubmission $submission): Form
    {
        $snapshot = $submission->instrument_snapshot;
        if (! $snapshot) {
            $submission->loadMissing('form.fields');

            return $submission->form;
        }
        $form = new Form;
        $form->forceFill($snapshot['form']);
        $form->exists = true;
        $fields = collect($snapshot['fields'])->map(function ($attributes) {
            $field = new FormField;
            $field->setRawAttributes($attributes, true);
            $field->exists = true;

            return $field;
        });
        $form->setRelation('fields', $fields);

        return $form;
    }
}
