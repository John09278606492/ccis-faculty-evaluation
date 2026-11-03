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

    /** Built snapshot forms by content hash: thousands of submissions usually share one instrument version. */
    private static array $snapshots = [];

    public static function forSubmission(FormSubmission $submission): Form
    {
        $raw = $submission->getAttributes()['instrument_snapshot'] ?? null;
        if (blank($raw)) {
            $submission->loadMissing('form.fields');

            return $submission->form;
        }
        $key = is_string($raw) ? md5($raw) : null;
        if ($key !== null && isset(self::$snapshots[$key])) {
            return self::$snapshots[$key];
        }
        if (count(self::$snapshots) > 200) {
            self::$snapshots = [];
        }
        $snapshot = $submission->instrument_snapshot;
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

        return $key !== null ? self::$snapshots[$key] = $form : $form;
    }
}
