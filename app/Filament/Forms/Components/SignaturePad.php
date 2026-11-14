<?php

namespace App\Filament\Forms\Components;

use App\Support\SignatureStrokes;
use Closure;
use Filament\Forms\Components\Field;

/**
 * Pen, finger or mouse signature. Its state is a JSON list of strokes, each a list of
 * [x, y] points, which App\Support\SignatureStrokes turns into the stored path.
 */
class SignaturePad extends Field
{
    protected string $view = 'filament.forms.components.signature-pad';

    protected function setUp(): void
    {
        parent::setUp();

        $this->required();
        $this->validationMessages(['required' => 'Please draw your signature before saving.']);
        $this->rules([
            fn (): Closure => function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! SignatureStrokes::pathFrom($value)) {
                    $fail('Please draw your full signature before saving.');
                }
            },
        ]);
    }
}
