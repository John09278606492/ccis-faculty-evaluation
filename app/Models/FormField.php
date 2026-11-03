<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $field) {
            if ($field->exists && $field->isDirty('form_id')) {
                Form::findOrFail($field->getRawOriginal('form_id'))->assertEditable();
            }
            if (! $field->exists || $field->isDirty()) {
                $field->form()->firstOrFail()->assertEditable();
            }
        });
        static::deleting(fn (self $field) => $field->form()->firstOrFail()->assertEditable());
    }

    public function getQuestionTextAttribute(): string
    {
        return preg_replace('/^\s*\d+[.)]\s+/u', '', $this->label);
    }

    protected $fillable = [
        'form_id',
        'section', // Group fields into sections (e.g., "A. Commitment")
        'name',
        'label',
        'type',
        'placeholder',
        'help_text',
        'column_values',
        'options',
        'validation_rules',
        'is_required',
        'order',
    ];

    protected $casts = [
        'options' => 'array',
        'validation_rules' => 'array',
        'column_values' => 'array',
        'is_required' => 'boolean',
    ];

    /**
     * Available field types.
     */
    public const TYPES = [
        'section_header' => 'Section Header',
        'text' => 'Text Input',
        'textarea' => 'Text Area',
        'select' => 'Dropdown (Single)',
        'radio' => 'Radio Buttons',
        'rating' => 'Star Rating',
        'likert_scale' => 'Likert Scale (5-point)', // For NBC 481 style ratings
    ];

    /**
     * Types that require options.
     */
    public const TYPES_WITH_OPTIONS = [
        'select',
        'radio',
    ];

    /**
     * Get the form this field belongs to.
     */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /**
     * Check if this field type requires options.
     */
    public function requiresOptions(): bool
    {
        return in_array($this->type, self::TYPES_WITH_OPTIONS);
    }

    /**
     * Get the Laravel validation rules for this field.
     * Note: `validation_rules` is cast as `array`, so $value is already decoded.
     */
    public function getValidationRulesAttribute($value): array
    {
        $rules = is_string($value) ? (json_decode($value, true) ?? []) : ($value ?? []);

        if ($this->is_required) {
            array_unshift($rules, 'required');
        } else {
            array_unshift($rules, 'nullable');
        }

        return $rules;
    }
}
