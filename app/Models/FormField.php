<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormField extends Model
{
    use HasFactory;

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
        'number' => 'Number',
        'email' => 'Email',
        'tel' => 'Phone Number',
        'url' => 'URL',
        'date' => 'Date',
        'datetime' => 'Date & Time',
        'time' => 'Time',
        'select' => 'Dropdown (Single)',
        'multiselect' => 'Dropdown (Multiple)',
        'checkbox' => 'Checkbox (Yes/No)',
        'checkboxlist' => 'Checkbox List',
        'radio' => 'Radio Buttons',
        'file' => 'File Upload',
        'rating' => 'Star Rating',
        'likert_scale' => 'Likert Scale (5-point)', // For NBC 481 style ratings
    ];

    /**
     * Types that require options.
     */
    public const TYPES_WITH_OPTIONS = [
        'select',
        'multiselect',
        'checkboxlist',
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
