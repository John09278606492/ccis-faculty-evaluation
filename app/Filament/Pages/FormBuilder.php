<?php

namespace App\Filament\Pages;

use App\Models\Form;
use App\Models\FormField;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;

class FormBuilder extends Page
{
    use \App\Filament\Concerns\HasBuilderWorkspaceDraft;
    protected static string $view = 'filament.pages.form-builder';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Form Builder';

    #[Locked]
    public ?int $formId = null;

    public ?Form $formRecord = null;

    public array $fields = [];

    public ?int $selectedFieldId = null;

    public array $selectedFieldData = [];

    public bool $showProperties = false;

    public bool $hasCustomScale = false;

    public array $fieldColumns = [];

    public ?int $selectedSectionId = null;

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

        abort_unless(auth()->user()->can('update', $this->formRecord), 403, 'Duplicate the shared template into your department before editing.');
        $this->formRecord->assertEditable();
        $this->prepareSectionHeaders();
        $this->loadFields();
        $this->hasCustomScale = ! empty($this->formRecord->rating_scale);
        $this->fieldColumns = $this->formRecord->field_columns ?? [];
    }

    public function hydrate(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isSuperAdmin() || $user->can('update_form')), 403);
        $this->formRecord = Form::findOrFail($this->formId);
        $tenant = Filament::getTenant();
        abort_unless($tenant && $this->formRecord->campuses()->whereKey($tenant)->exists(), 403);
        abort_unless(auth()->user()->can('update', $this->formRecord), 403, 'Duplicate the shared template into your department before editing.');
        $this->formRecord->assertEditable();
        $this->hasCustomScale = ! empty($this->formRecord->rating_scale);
    }

    private function prepareSectionHeaders(): void
    {
        DB::transaction(function () {
            $this->formRecord->newQuery()->whereKey($this->formId)->lockForUpdate()->first();
            $current = null;
            $order = 0;
            foreach ($this->formRecord->fields()->orderBy('order')->orderBy('id')->get() as $field) {
                if ($field->type === 'section_header') {
                    $current = $field->label;
                } elseif (filled($field->section) && $field->section !== $current) {
                    $current = $field->section;
                    $this->formRecord->fields()->create(['name' => 'section_'.Str::random(12), 'label' => $current, 'section' => $current, 'type' => 'section_header', 'is_required' => false, 'order' => ++$order]);
                }
                $field->update(['order' => ++$order]);
            }
        });
    }

    public function getSections(): array
    {
        $sections = [];
        $current = null;
        foreach ($this->fields as $field) {
            if ($field['type'] === 'section_header') {
                $current = $field['id'];
                $sections[$current] = ['id' => $current, 'label' => $field['label'], 'count' => 0];
            } elseif ($current !== null) {
                $sections[$current]['count']++;
            }
        }

        return $sections;
    }

    public function addQuestionToSection(int $sectionId, ?string $type = null): void
    {
        $section = $this->formRecord->fields()->where('type', 'section_header')->findOrFail($sectionId);
        $next = $this->formRecord->fields()->where('type', 'section_header')->where('order', '>', $section->order)->min('order');
        $last = $this->formRecord->fields()->where('order', '>=', $section->order)->when($next, fn ($q) => $q->where('order', '<', $next))->orderByDesc('order')->first();
        $this->addField($type ?? ($this->hasCustomScale ? 'likert_scale' : 'text'), $last->id);
    }

    public function addSelectedQuestion(string $type): void
    {
        $sectionId = ($this->selectedFieldData['type'] ?? null) === 'section_header' ? $this->selectedFieldId : $this->selectedSectionId;
        if ($sectionId) {
            $this->addQuestionToSection($sectionId, $type);
        } else {
            $this->addField($type);
        }
    }

    public function moveSection(int $sectionId, int $direction): void
    {
        abort_unless(in_array($direction, [-1, 1]), 422);
        $blocks = [];
        $prefix = [];
        $current = null;
        foreach ($this->fields as $field) {
            if ($field['type'] === 'section_header') {
                $current = $field['id'];
                $blocks[$current] = [];
            }
            if ($current === null) {
                $prefix[] = $field['id'];
            } else {
                $blocks[$current][] = $field['id'];
            }
        }
        $keys = array_keys($blocks);
        $index = array_search($sectionId, $keys, true);
        if ($index === false || ! isset($keys[$index + $direction])) {
            return;
        }
        [$keys[$index], $keys[$index + $direction]] = [$keys[$index + $direction], $keys[$index]];
        $ids = $prefix;
        foreach ($keys as $key) {
            $ids = array_merge($ids, $blocks[$key]);
        }
        $this->reorderFields($ids);
    }

    private function moveQuestion(int $fieldId, ?int $sectionId): void
    {
        $rows = $this->formRecord->fields()->orderBy('order')->get()->reject(fn ($field) => $field->id === $fieldId)->values();
        $position = 0;
        if ($sectionId !== null) {
            $start = $rows->search(fn ($field) => $field->id === $sectionId && $field->type === 'section_header');
            abort_if($start === false, 422);
            $position = $start + 1;
            while ($position < $rows->count() && $rows[$position]->type !== 'section_header') {
                $position++;
            }
        } else {
            while ($position < $rows->count() && $rows[$position]->type !== 'section_header') {
                $position++;
            }
        }
        $ids = $rows->pluck('id')->all();
        array_splice($ids, $position, 0, [$fieldId]);
        $this->reorderFields($ids);
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
        abort_unless(collect(static::getFieldCategories())->flatMap(fn ($types) => array_keys($types))->contains($type), 422);
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
        $this->stashBuilderWorkspaceDraft();
        $field = FormField::find($fieldId);
        if (! $field || $field->form_id !== $this->formId) {
            return;
        }

        $this->selectedFieldId = $fieldId;
        $this->selectedSectionId = $this->formRecord->fields()->where('type', 'section_header')->where('order', '<', $field->order)->orderByDesc('order')->value('id');
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
        $this->beginBuilderWorkspaceDraft();
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

        $this->selectedFieldData['label'] = trim($this->selectedFieldData['label'] ?? '');
        $this->validate([
            'selectedFieldData.label' => ['required', 'string', 'max:1000', ...($field->type === 'section_header' ? [Rule::unique('form_fields', 'label')->where('form_id', $this->formId)->where('type', 'section_header')->ignore($field->id)] : [])],
            'selectedFieldData.name' => ['required', 'string', 'max:255', Rule::unique('form_fields', 'name')->where('form_id', $this->formId)->ignore($field->id)],
            'selectedSectionId' => ['nullable', Rule::exists('form_fields', 'id')->where('form_id', $this->formId)->where('type', 'section_header')],
        ]);
        $previousLabel = $field->label;
        $currentSectionId = $this->formRecord->fields()->where('type', 'section_header')->where('order', '<', $field->order)->orderByDesc('order')->value('id');
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

        if ($field->type === 'section_header' && $previousLabel !== $field->label) {
            $settings = $this->formRecord->settings ?? [];
            if (isset($settings['section_descriptions'][$previousLabel])) {
                $settings['section_descriptions'][$field->label] = $settings['section_descriptions'][$previousLabel];
                unset($settings['section_descriptions'][$previousLabel]);
            }
            $config = $this->formRecord->scoring_config ?? [];
            foreach ($config['section_weights'] ?? [] as $index => $weight) {
                if (($weight['section_name'] ?? null) === $previousLabel) {
                    $config['section_weights'][$index]['section_name'] = $field->label;
                }
            }
            $this->formRecord->update(['settings' => $settings, 'scoring_config' => $config]);
        } elseif ($field->type !== 'section_header' && $currentSectionId !== $this->selectedSectionId) {
            $this->moveQuestion($field->id, $this->selectedSectionId);
        }

        // Recalculate sections (especially if a section_header label changed)
        $this->recalculateSections();
        $this->loadFields();

        $this->selectField($field->id);

        $this->completeWorkspaceDraft();

        $this->dispatch('notify', message: 'Field saved successfully');
    }

    /**
     * Reorder fields from drag-and-drop.
     */
    public function reorderFields(array $orderedIds): void
    {
        $actual = $this->formRecord->fields()->pluck('id')->sort()->values()->all();
        $received = collect($orderedIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        abort_unless($actual === $received, 422);
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

        if ($field->type === 'section_header') {
            $next = $this->formRecord->fields()->where('type', 'section_header')->where('order', '>', $field->order)->min('order');
            if ($this->formRecord->fields()->where('order', '>', $field->order)->when($next, fn ($q) => $q->where('order', '<', $next))->exists()) {
                $this->dispatch('notify', message: 'Move the questions to another section before deleting this section.');

                return;
            }
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
        $this->stashBuilderWorkspaceDraft();
        $this->selectedFieldId = null;
        $this->selectedFieldData = [];
        $this->showProperties = false;
        $this->beginBuilderWorkspaceDraft();
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

    public function previewAction(): Action
    {
        return Action::make('preview')->modalHeading('Form Preview')->modalWidth('7xl')
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('reports.preview-frame', ['url' => route('form.preview', $this->formRecord), 'title' => 'Evaluation form preview', 'hint' => 'Preview the evaluator form. Submissions are disabled.']));
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
