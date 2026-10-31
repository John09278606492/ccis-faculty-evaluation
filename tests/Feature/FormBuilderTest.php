<?php

use App\Filament\Pages\FormBuilder;
use App\Models\Campus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Services\EvaluationTemplates;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The drag-and-drop form builder an administrator uses to design a form.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function formBuilderAdmin(): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('update_form', 'web'));

    return $admin;
}

/**
 * The shared fixture's form already has a rating scale, which locks the
 * builder to likert fields. Clear it for the tests that add other kinds.
 */
function formWithoutCustomScale($form): void
{
    $form->update(['rating_scale' => null]);
}

it('preserves imported section groups when a question is edited and opens them as visible sections', function () {
    ['offering' => $offering] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('A', $offering->campus);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());
    $first = $form->fields()->first();
    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    expect($form->fields()->where('type', 'section_header')->count())->toBe(3);
    $builder->call('selectField', $first->id)->set('selectedFieldData.label', 'Updated wording')->call('saveFieldProperties')->assertHasNoErrors();
    expect($first->fresh()->name)->toBe('criterion_1')->and($first->fresh()->section)->toBe('A. Management of Teaching and Learning');
    expect($form->fields()->where('type', 'likert_scale')->get()->groupBy('section')->map->count()->values()->all())->toBe([6, 5, 4]);
    Livewire::test(FormBuilder::class, ['record' => $form->id]);
    expect($form->fields()->where('type', 'section_header')->count())->toBe(3);
});

it('adds questions to the chosen section and moves complete sections together', function () {
    ['offering' => $offering] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('A', $offering->campus);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());
    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $headers = $form->fields()->where('type', 'section_header')->get();
    $builder->call('addQuestionToSection', $headers[0]->id);
    $new = $form->fields()->where('type', 'likert_scale')->orderByDesc('id')->first();
    expect($new->section)->toBe($headers[0]->label);
    $builder->set('selectedSectionId', $headers[2]->id)->call('saveFieldProperties')->assertHasNoErrors();
    expect($new->fresh()->section)->toBe($headers[2]->label);
    $builder->call('moveSection', $headers[2]->id, -1);
    expect($form->fields()->where('type', 'section_header')->pluck('id')->all())->toBe([$headers[0]->id, $headers[2]->id, $headers[1]->id]);
    expect($form->fields()->where('type', 'likert_scale')->where('section', $headers[2]->label)->count())->toBe(5);
    $builder->call('deleteField', $headers[2]->id)->assertDispatched('notify');
    expect($headers[2]->fresh())->not->toBeNull();
});

it('keeps scoring weights and section descriptions when renaming a section', function () {
    ['offering' => $offering] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('A', $offering->campus);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());
    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $header = $form->fields()->where('type', 'section_header')->first();
    $form->update(['scoring_config' => ['section_weights' => [['section_name' => $header->label, 'weight' => 40]]]]);
    $builder->call('selectField', $header->id)->set('selectedFieldData.label', 'Teaching and Learning')->call('saveFieldProperties')->assertHasNoErrors();
    expect($form->fresh()->settings['section_descriptions']['Teaching and Learning'])->not->toBeEmpty()
        ->and($form->fresh()->scoring_config['section_weights'][0]['section_name'])->toBe('Teaching and Learning');
    expect($form->fields()->where('type', 'likert_scale')->where('section', 'Teaching and Learning')->count())->toBe(6);
});

it('refuses the form builder to a user without the permission', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(User::factory()->create());

    Livewire::test(FormBuilder::class, ['record' => $form->id])->assertForbidden();
});

it('refuses the form builder for a form from another campus', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $other = Campus::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true]);
    $form->campuses()->sync([$other->id]);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    Livewire::test(FormBuilder::class, ['record' => $form->id])->assertForbidden();
});

it('opens the form builder for a permitted administrator', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    Livewire::test(FormBuilder::class, ['record' => $form->id])
        ->assertSuccessful()
        ->assertSet('formId', $form->id);
});

it('adds a field to the form', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    Livewire::test(FormBuilder::class, ['record' => $form->id])
        ->call('addField', 'text')
        ->assertHasNoErrors();

    $field = $form->fields()->where('type', 'text')->sole();
    expect($field->label)->toBe('Text Input')
        ->and($field->name)->toBe('text_input')
        ->and($field->order)->toBe(2);
});

it('names a second field of the same type differently', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'text')->call('addField', 'text');

    expect($form->fields()->where('type', 'text')->pluck('label')->all())->toBe(['Text Input', 'Text Input 2']);
});

it('gives a section header a lettered label', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    Livewire::test(FormBuilder::class, ['record' => $form->id])->call('addField', 'section_header');

    expect($form->fields()->where('type', 'section_header')->sole()->label)->toBe('Section A');
});

it('keeps only likert fields when the form has a custom scale', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->update(['rating_scale' => [['value' => 5, 'label' => 'Excellent']]]);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    Livewire::test(FormBuilder::class, ['record' => $form->id])
        ->assertSet('hasCustomScale', true)
        ->call('addField', 'text')
        ->assertDispatched('notify');

    expect($form->fields()->where('type', 'text')->count())->toBe(0);
});

it('saves the properties of a field', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'text');
    $field = $form->fields()->where('type', 'text')->sole();

    $builder->set('selectedFieldId', $field->id)
        ->set('selectedFieldData.label', 'What went well')
        ->set('selectedFieldData.name', 'what_went_well')
        ->set('selectedFieldData.is_required', true)
        ->call('saveFieldProperties')
        ->assertHasNoErrors();

    $field->refresh();
    expect($field->label)->toBe('What went well')
        ->and($field->name)->toBe('what_went_well')
        ->and($field->is_required)->toBeTrue();
});

it('puts the fields under the section they belong to', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'section_header');
    $builder->call('addField', 'text');

    expect($form->fields()->where('type', 'text')->sole()->section)->toBe('Section A');
});

it('reorders the fields', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'text');
    $second = $form->fields()->where('type', 'text')->sole();
    $first = $form->fields()->where('name', 'teaching_quality')->sole();

    $builder->call('reorderFields', [$second->id, $first->id]);

    expect($form->fields()->orderBy('order')->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('deletes a field and closes the properties panel', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'text');
    $field = $form->fields()->where('type', 'text')->sole();

    $builder->call('deleteField', $field->id)
        ->assertSet('selectedFieldId', null)
        ->assertSet('showProperties', false);

    expect($form->fields()->where('type', 'text')->count())->toBe(0);
});

it('duplicates a field', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'text');
    $field = $form->fields()->where('type', 'text')->sole();

    $builder->call('duplicateField', $field->id);

    $copy = $form->fields()->where('label', 'Text Input (Copy)')->sole();
    expect($copy->id)->not->toBe($field->id)
        ->and($copy->order)->toBe($field->order + 1);
});

it('ignores a field that belongs to another form', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $other = Form::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true]);
    $foreign = FormField::create([
        'form_id' => $other->id,
        'name' => 'foreign',
        'label' => 'Foreign',
        'type' => 'text',
        'is_required' => false,
        'order' => 1,
    ]);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    Livewire::test(FormBuilder::class, ['record' => $form->id])
        ->call('deleteField', $foreign->id);

    expect($foreign->fresh())->not->toBeNull();
});

it('adds and removes the options of the selected field', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    formWithoutCustomScale($form);
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(formBuilderAdmin());

    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id]);
    $builder->call('addField', 'select');
    $field = $form->fields()->where('type', 'select')->sole();

    $builder->set('selectedFieldId', $field->id)
        ->call('addOption')
        ->call('addOption')
        ->call('removeOption', 0)
        ->call('saveFieldProperties');

    // A new choice field starts with two options; two more are added and one removed.
    expect($field->fresh()->options)->toHaveCount(3)
        ->and($field->fresh()->options[0]['value'])->toBe('option_2');
});
