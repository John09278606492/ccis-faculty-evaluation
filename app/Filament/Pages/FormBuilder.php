<?php

namespace App\Filament\Pages;

use App\Models\Form;
use App\Models\FormField;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Str;

class FormBuilder extends Page
{
    protected static string $view = 'filament.pages.form-builder';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Form Builder';

    public ?int $formId = null;

    public ?Form $formRecord = null;

    public array $fields = [];

    public ?int $selectedFieldId = null;

    public array $selectedFieldData = [];

    public bool $showProperties = false;

    public bool $hasCustomScale = false;

    public array $fieldColumns = [];

    /**
     * Available field types grouped by category.
     */
    public static function getFieldCategories(): array
    {
        return [
            'Structure' => [
                'section_header' => ['label' => 'Section', 'icon' => '§'],
            ],
            'Input Fields' => [
                'text' => ['label' => 'Text Input', 'icon' => 'T'],
                'textarea' => ['label' => 'Text Area', 'icon' => '¶'],
                'number' => ['label' => 'Number', 'icon' => '#'],
                'email' => ['label' => 'Email', 'icon' => '@'],
                'tel' => ['label' => 'Phone', 'icon' => '📱'],
                'url' => ['label' => 'URL', 'icon' => '🔗'],
            ],
            'Choice Fields' => [
                'select' => ['label' => 'Dropdown', 'icon' => '▾'],
                'multiselect' => ['label' => 'Multi-Select', 'icon' => '☰'],
                'checkbox' => ['label' => 'Checkbox', 'icon' => '☑'],
                'checkboxlist' => ['label' => 'Checkbox List', 'icon' => '☑☑'],
                'radio' => ['label' => 'Radio Buttons', 'icon' => '◉'],
            ],
            'Date & Time' => [
                'date' => ['label' => 'Date', 'icon' => '📅'],
                'datetime' => ['label' => 'Date & Time', 'icon' => '📅⏰'],
                'time' => ['label' => 'Time', 'icon' => '⏰'],
            ],
            'Special' => [
                'file' => ['label' => 'File Upload', 'icon' => '📎'],
                'rating' => ['label' => 'Star Rating', 'icon' => '⭐'],
                'likert_scale' => ['label' => 'Likert Scale', 'icon' => '📊'],
            ],
        ];
    }

    /**
     * Get field categories filtered by whether custom rating scale is active.
     * When custom scale is active, only allow Likert Scale fields.
     */
    public function getFilteredFieldCategories(): array
    {
        $structure = [
            'Structure' => [
                'section_header' => ['label' => 'Section', 'icon' => '§'],
            ],
        ];

        if ($this->hasCustomScale) {
            return array_merge($structure, [
                'Rating Fields' => [
                    'likert_scale' => ['label' => 'Likert Scale', 'icon' => '📊'],
                ],
            ]);
        }

        return static::getFieldCategories();
    }

    public function mount(int $record): void
    {
        $this->formId = $record;
        $this->formRecord = Form::findOrFail($record);

        // Authorization: check user has permission to edit forms
        $user = auth()->user();
        abort_unless(
            $user->isSuperAdmin() || $user->can('update_form'),
            403,
            'You do not have permission to edit forms.'
        );

        // Tenant check: verify form belongs to the user's current campus
        $tenant = Filament::getTenant();
        if ($tenant && ! $this->formRecord->campuses()->where('campus_id', $tenant->id)->exists()) {
            abort(403, 'This form does not belong to your campus.');
        }

        $this->loadFields();
        $this->hasCustomScale = ! empty($this->formRecord->rating_scale);
        $this->fieldColumns = $this->formRecord->field_columns ?? [];
    }

    /**
     * Walk through fields in order and auto-assign sections.
     * Every field after a section_header gets that header's label as its section,
     * until the next section_header or end of list.
     */
    public function recalculateSections(): void
    {
        $currentSection = null;

        $fields = $this->formRecord->fields()->orderBy('order')->get();

        foreach ($fields as $field) {
            if ($field->type === 'section_header') {
                $currentSection = $field->label;
                // Section headers store their own label as the section too
                if ($field->section !== $currentSection) {
                    $field->update(['section' => $currentSection]);
                }
            } else {
                if ($field->section !== $currentSection) {
                    $field->update(['section' => $currentSection]);
                }
            }
        }
    }

    public function loadFields(): void
    {
        $this->fields = $this->formRecord->fields()
            ->orderBy('order')
            ->get()
            ->map(fn (FormField $f) => [
                'id' => $f->id,
                'section' => $f->section,
                'label' => $f->label,
                'name' => $f->name,
                'type' => $f->type,
                'placeholder' => $f->placeholder,
                'help_text' => $f->help_text,
                'column_values' => $f->column_values ?? [],
                'options' => $f->getRawOriginal('options') ? json_decode($f->getRawOriginal('options'), true) : [],
                'is_required' => $f->is_required,
                'order' => $f->order,
            ])
            ->toArray();
    }

    /**
     * Add a new field to the canvas.
     */
    public function addField(string $type, ?int $afterFieldId = null): void
    {
        // When custom rating scale is active, only allow likert_scale and section_header fields
        if ($this->hasCustomScale && ! in_array($type, ['likert_scale', 'section_header'])) {
            $this->dispatch('notify', message: 'Only Likert Scale fields are allowed when custom rating scale is active.');

            return;
        }

        // Determine label
        if ($type === 'section_header') {
            $existingCount = collect($this->fields)->where('type', 'section_header')->count();
            $label = 'Section '.chr(65 + $existingCount); // A, B, C...
        } else {
            $typeLabel = FormField::TYPES[$type] ?? ucfirst($type);
            $existingCount = collect($this->fields)->where('type', $type)->count();
            $label = $typeLabel.($existingCount > 0 ? ' '.($existingCount + 1) : '');
        }

        // Determine order
        if ($afterFieldId) {
            $afterField = collect($this->fields)->firstWhere('id', $afterFieldId);
            $order = $afterField ? $afterField['order'] + 1 : count($this->fields) + 1;

            // Shift subsequent fields
            FormField::where('form_id', $this->formId)
                ->where('order', '>=', $order)
                ->increment('order');
        } else {
            $order = count($this->fields) + 1;
        }

        $field = FormField::create([
            'form_id' => $this->formId,
            'label' => $label,
            'name' => $type === 'section_header' ? Str::snake($label).'_header' : Str::snake($label),
            'type' => $type,
            'is_required' => false,
            'order' => $order,
            'options' => in_array($type, FormField::TYPES_WITH_OPTIONS)
                ? [['value' => 'option_1', 'label' => 'Option 1'], ['value' => 'option_2', 'label' => 'Option 2']]
                : null,
        ]);

        $this->recalculateSections();
        $this->loadFields();
        $this->selectField($field->id);
    }

    /**
     * Select a field and load its properties into the panel.
     */
    public function selectField(int $fieldId): void
    {
        $field = FormField::find($fieldId);
        if (! $field || $field->form_id !== $this->formId) {
            return;
        }

        $this->selectedFieldId = $fieldId;
        $this->selectedFieldData = [
            'label' => $field->label,
            'name' => $field->name,
            'section' => $field->section ?? '',
            'type' => $field->type,
            'placeholder' => $field->placeholder ?? '',
            'help_text' => $field->help_text ?? '',
            'is_required' => $field->is_required,
            'column_values' => $field->column_values ?? [],
            'options' => $field->getRawOriginal('options') ? json_decode($field->getRawOriginal('options'), true) : [],
        ];
        $this->showProperties = true;
    }

    /**
     * Save field properties from the right panel.
     */
    public function saveFieldProperties(): void
    {
        if (! $this->selectedFieldId) {
            return;
        }

        $field = FormField::find($this->selectedFieldId);
        if (! $field || $field->form_id !== $this->formId) {
            return;
        }

        $field->update([
            'label' => $this->selectedFieldData['label'],
            'name' => $this->selectedFieldData['name'],
            'placeholder' => $this->selectedFieldData['placeholder'] ?: null,
            'help_text' => $this->selectedFieldData['help_text'] ?: null,
            'is_required' => $this->selectedFieldData['is_required'] ?? false,
            'column_values' => ! empty($this->selectedFieldData['column_values'])
                ? $this->selectedFieldData['column_values']
                : null,
            'options' => in_array($field->type, FormField::TYPES_WITH_OPTIONS)
                ? ($this->selectedFieldData['options'] ?? [])
                : $field->options,
        ]);

        // Recalculate sections (especially if a section_header label changed)
        $this->recalculateSections();
        $this->loadFields();

        $this->dispatch('notify', message: 'Field saved successfully');
    }

    /**
     * Reorder fields from drag-and-drop.
     */
    public function reorderFields(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            FormField::where('id', $id)
                ->where('form_id', $this->formId)
                ->update(['order' => $index + 1]);
        }

        $this->recalculateSections();
        $this->loadFields();
    }

    /**
     * Delete a field.
     */
    public function deleteField(int $fieldId): void
    {
        $field = FormField::find($fieldId);
        if (! $field || $field->form_id !== $this->formId) {
            return;
        }

        $field->delete();

        if ($this->selectedFieldId === $fieldId) {
            $this->selectedFieldId = null;
            $this->selectedFieldData = [];
            $this->showProperties = false;
        }

        // Re-sequence order
        $this->formRecord->fields()->orderBy('order')->get()
            ->each(fn ($f, $i) => $f->update(['order' => $i + 1]));

        $this->recalculateSections();
        $this->loadFields();
    }

    /**
     * Duplicate a field.
     */
    public function duplicateField(int $fieldId): void
    {
        $field = FormField::find($fieldId);
        if (! $field || $field->form_id !== $this->formId) {
            return;
        }

        // Shift fields after this one
        FormField::where('form_id', $this->formId)
            ->where('order', '>', $field->order)
            ->increment('order');

        $newField = $field->replicate();
        $newField->label = $field->label.' (Copy)';
        $newField->name = Str::snake($newField->label);
        $newField->order = $field->order + 1;
        $newField->save();

        $this->recalculateSections();
        $this->loadFields();
        $this->selectField($newField->id);
    }

    /**
     * Close the properties panel.
     */
    public function closeProperties(): void
    {
        $this->selectedFieldId = null;
        $this->selectedFieldData = [];
        $this->showProperties = false;
    }

    /**
     * Add an option to the selected field's options array.
     */
    public function addOption(): void
    {
        $count = count($this->selectedFieldData['options'] ?? []);
        $this->selectedFieldData['options'][] = [
            'value' => 'option_'.($count + 1),
            'label' => 'Option '.($count + 1),
        ];
    }

    /**
     * Remove an option from the selected field's options array.
     */
    public function removeOption(int $index): void
    {
        unset($this->selectedFieldData['options'][$index]);
        $this->selectedFieldData['options'] = array_values($this->selectedFieldData['options']);
    }

    /**
     * Add a custom column that applies to all fields.
     */
    public function addFieldColumn(): void
    {
        $count = count($this->fieldColumns);
        $this->fieldColumns[] = [
            'key' => 'column_'.($count + 1),
            'label' => 'Column '.($count + 1),
        ];
        $this->saveFieldColumns();
    }

    /**
     * Remove a custom column.
     */
    public function removeFieldColumn(int $index): void
    {
        $removedKey = $this->fieldColumns[$index]['key'] ?? null;
        unset($this->fieldColumns[$index]);
        $this->fieldColumns = array_values($this->fieldColumns);
        $this->saveFieldColumns();

        // Clean up column_values from all fields
        if ($removedKey) {
            foreach ($this->formRecord->fields()->get() as $field) {
                $values = $field->column_values ?? [];
                unset($values[$removedKey]);
                $field->update(['column_values' => $values ?: null]);
            }
            $this->loadFields();
        }
    }

    /**
     * Save field column definitions to the form.
     */
    public function saveFieldColumns(): void
    {
        $this->formRecord->update(['field_columns' => $this->fieldColumns]);
        $this->dispatch('notify', message: 'Columns updated');
    }

    /**
     * Update a column value for the selected field (called from Alpine via rich text editor).
     */
    public function updateColumnValue(string $columnKey, string $content): void
    {
        if (! $this->selectedFieldId) {
            return;
        }

        $this->selectedFieldData['column_values'][$columnKey] = $content;
    }

    public function getTitle(): string
    {
        return 'Form Builder — '.($this->formRecord->name ?? 'Untitled');
    }

    public static function getRoutePath(): string
    {
        return '/forms/{record}/builder';
    }

    public static function getSlug(): string
    {
        return 'forms/{record}/builder';
    }
}
