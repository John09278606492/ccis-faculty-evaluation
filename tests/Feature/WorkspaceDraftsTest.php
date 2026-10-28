<?php

use App\Filament\Pages\FormBuilder;
use App\Filament\Pages\QuizBuilder;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\SectionResource\Pages\CreateSection;
use App\Filament\Resources\SectionResource\Pages\EditSection;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\Quiz;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Models\WorkspaceDraft;
use App\Services\AcademicSections;
use App\Services\EvaluationTemplates;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['offering' => $this->offering, 'student' => $this->student] = evaluationFixture(false);
    $this->campus = $this->offering->campus;
    $this->actor = $this->offering->instructor;
    $this->actor->campuses()->attach($this->campus);
    $this->actor->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach (['view_academic_students', 'manage_academic_students', 'view_course::offering', 'update_course::offering', 'manage_evaluators_course::offering', 'update_form'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->section = app(AcademicSections::class)->save($this->actor, $this->campus, [
        'name' => 'BSIT 3A', 'department_id' => $this->offering->course->department_id,
        'semester_id' => $this->offering->semester_id, 'is_active' => true,
    ]);
});

it('recovers a private encrypted create draft only on the same form without creating a record', function () {
    $page = Livewire::test(CreateSection::class)->fillForm(['name' => 'UNFINISHED-SECTION']);
    $data = $page->get('data');
    $page->call('persistWorkspaceDraft', ['data' => [...$data, 'password' => 'DO-NOT-STORE', 'unexpected' => 'IGNORE']])->assertHasNoErrors();
    expect(AcademicSection::where('name', 'UNFINISHED-SECTION')->exists())->toBeFalse();
    $row = WorkspaceDraft::sole();
    expect($row->getRawOriginal('payload'))->not->toContain('UNFINISHED-SECTION')
        ->and($row->payload['data']['name'])->toBe('UNFINISHED-SECTION')
        ->and($row->payload['data'])->not->toHaveKeys(['password', 'unexpected']);
    Livewire::test(CreateSection::class)->assertSet('workspaceDraftAvailable', true)->assertFormSet(['name' => null])
        ->call('resumeWorkspaceDraft')->assertHasNoErrors()->assertFormSet(['name' => 'UNFINISHED-SECTION'])->assertSet('workspaceDraftAvailable', false);
    Livewire::test(EditSection::class, ['record' => $this->section->id])->assertSet('workspaceDraftAvailable', false);
});

it('clears a draft after a successful save but keeps it when validation fails', function () {
    $page = Livewire::test(EditSection::class, ['record' => $this->section->id])->fillForm(['name' => 'BSIT 3B']);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')])->call('save')->assertHasNoFormErrors();
    expect(WorkspaceDraft::sole()->payload)->toBe([])->and($this->section->fresh()->name)->toBe('BSIT 3B');
    Livewire::test(EditSection::class, ['record' => $this->section->id])->assertSet('workspaceDraftAvailable', false);
    $page = Livewire::test(CreateSection::class)->fillForm(['name' => 'Invalid unfinished section', 'department_id' => null]);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')])->call('create')->assertHasFormErrors(['department_id']);
    Livewire::test(CreateSection::class)->assertSet('workspaceDraftAvailable', true);
});

it('discards working edits without changing the saved section and resets the form', function () {
    $page = Livewire::test(EditSection::class, ['record' => $this->section->id])->fillForm(['name' => 'Do not apply']);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')])->call('discardWorkspaceDraft')
        ->assertFormSet(['name' => 'BSIT 3A'])->assertSet('workspaceDraftAvailable', false);
    expect(WorkspaceDraft::sole()->payload)->toBe([])->and($this->section->fresh()->name)->toBe('BSIT 3A');
});

it('isolates drafts by user and campus and refuses a revoked editor', function () {
    $page = Livewire::test(CreateSection::class)->fillForm(['name' => 'Private working copy']);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')]);
    $other = User::factory()->create();
    $other->assignRole(Role::findOrCreate('super_admin', 'web'));
    $other->givePermissionTo(['view_academic_students', 'manage_academic_students']);
    $this->actingAs($other);
    Livewire::test(CreateSection::class)->assertSet('workspaceDraftAvailable', false);
    $this->actingAs($this->actor);
    $campus = Campus::create(['name' => 'Other campus', 'slug' => 'other', 'is_active' => true]);
    Filament::setTenant($campus, isQuiet: true);
    Livewire::test(CreateSection::class)->assertSet('workspaceDraftAvailable', false);
    Filament::setTenant($this->campus, isQuiet: true);
    $page = Livewire::test(CreateSection::class)->assertSet('workspaceDraftAvailable', true);
    $this->actor->syncRoles([]);
    $this->actor->syncPermissions([]);
    $page->call('resumeWorkspaceDraft')->assertForbidden();
});

it('will not replace a newer record or resurrect an expired draft', function () {
    $page = Livewire::test(EditSection::class, ['record' => $this->section->id])->fillForm(['name' => 'Old working copy']);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')]);
    $this->section->update(['name' => 'Current saved section']);
    Livewire::test(EditSection::class, ['record' => $this->section->id])->call('resumeWorkspaceDraft')->assertHasErrors(['workspaceDraft']);
    expect($this->section->fresh()->name)->toBe('Current saved section');
    WorkspaceDraft::query()->update(['expires_at' => now()->subSecond()]);
    Livewire::test(EditSection::class, ['record' => $this->section->id])->assertSet('workspaceDraftAvailable', false)
        ->call('resumeWorkspaceDraft')->assertHasErrors(['workspaceDraft']);
});

it('does not overwrite an unrecovered draft with newly loaded defaults', function () {
    $page = Livewire::test(CreateSection::class)->fillForm(['name' => 'Keep this draft']);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')]);
    Livewire::test(CreateSection::class)->call('persistWorkspaceDraft', ['data' => ['name' => 'Replacement']]);
    expect(WorkspaceDraft::sole()->payload['data']['name'])->toBe('Keep this draft');
});

it('rejects obsolete requests from another tab including requests arriving after Save', function () {
    $one = Livewire::test(EditSection::class, ['record' => $this->section->id]);
    $two = Livewire::test(EditSection::class, ['record' => $this->section->id]);
    $one->fillForm(['name' => 'First tab'])->call('persistWorkspaceDraft', ['data' => ['name' => 'First tab']]);
    $two->call('persistWorkspaceDraft', ['data' => ['name' => 'Old tab']])->assertHasErrors(['workspaceDraft']);
    $one->call('save')->assertHasNoFormErrors();
    $two->call('persistWorkspaceDraft', ['data' => ['name' => 'Late old request']])->assertHasErrors(['workspaceDraft']);
    expect(WorkspaceDraft::sole()->payload)->toBe([]);
});

it('restores the exact criteria editor without saving its draft as a live question', function () {
    $form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $field = $form->fields()->where('type', 'likert_scale')->first();
    $builder = Livewire::test(FormBuilder::class, ['record' => $form->id])->call('selectField', $field->id)->set('selectedFieldData.label', 'Draft wording');
    $builder->call('persistWorkspaceDraft', ['selectedFieldId' => $field->id, 'selectedFieldData' => $builder->get('selectedFieldData'), 'selectedSectionId' => $builder->get('selectedSectionId')]);
    expect($field->fresh()->label)->not->toBe('Draft wording');
    Livewire::test(FormBuilder::class, ['record' => $form->id])->assertSet('workspaceDraftAvailable', true)
        ->call('resumeWorkspaceDraft')->assertSet('selectedFieldId', $field->id)->assertSet('selectedFieldData.label', 'Draft wording')
        ->call('saveFieldProperties')->assertHasNoErrors();
    expect($field->fresh()->label)->toBe('Draft wording')->and(WorkspaceDraft::sole()->payload)->toBe([]);
});

it('keeps an unfinished evaluator selection on modal close and requires an explicit Save Evaluators', function () {
    $this->student->update(['student_department_id' => $this->section->department_id]);
    StudentAcademicRecord::create(['user_id' => $this->student->id, 'semester_id' => $this->section->semester_id, 'program' => 'BSIT']);
    app(AcademicSections::class)->add($this->actor, $this->campus, $this->section, [$this->student->id]);
    $key = $this->section->department_id.'~'.$this->section->semester_id.'~'.rawurlencode($this->section->name);
    $params = ['ownerRecord' => $this->offering, 'pageClass' => EditTeachingClass::class];
    Livewire::test(StudentsRelationManager::class, $params)->mountTableAction('assignSections')
        ->setTableActionData(['sections' => [$key], 'include' => [], 'exclude' => [], 'use_exceptions' => false])->call('unmountTableAction');
    expect($this->offering->students()->count())->toBe(0);
    Livewire::test(StudentsRelationManager::class, $params)->assertSet('workspaceDraftAvailable', true)
        ->call('resumeWorkspaceDraft')->assertTableActionDataSet(['sections' => [$key]])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->offering->students()->pluck('users.id')->all())->toBe([$this->student->id])->and(WorkspaceDraft::sole()->payload)->toBe([]);
});

it('bounds draft size before persisting content', function () {
    $page = Livewire::test(CreateSection::class);
    $page->call('persistWorkspaceDraft', ['data' => ['name' => str_repeat('x', 513000)]])->assertHasErrors(['workspaceDraft']);
    expect(WorkspaceDraft::count())->toBe(0);
});

it('preserves a criteria edit on an immediate switch and clears Create and create another', function () {
    $form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $fields = $form->fields()->where('type', 'likert_scale')->take(2)->get();
    Livewire::test(FormBuilder::class, ['record' => $form->id])->call('selectField', $fields[0]->id)
        ->set('selectedFieldData.label', 'Do not lose this edit')->call('selectField', $fields[1]->id)->assertSet('workspaceDraftAvailable', true);
    Livewire::test(FormBuilder::class, ['record' => $form->id])->call('resumeWorkspaceDraft')
        ->assertSet('selectedFieldId', $fields[0]->id)->assertSet('selectedFieldData.label', 'Do not lose this edit');
    $page = Livewire::test(CreateSection::class)->fillForm(['name' => 'BSIT 3C', 'department_id' => $this->section->department_id, 'semester_id' => $this->section->semester_id, 'is_active' => true]);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')])->call('create', true)->assertHasNoFormErrors()->assertFormSet(['name' => null]);
    Livewire::test(CreateSection::class)->assertSet('workspaceDraftAvailable', false);
});

it('recovers quiz question properties while retaining normal validation and explicit saving', function () {
    $this->actor->givePermissionTo(Permission::findOrCreate('update_quiz', 'web'));
    $quiz = Quiz::create(['course_offering_id' => $this->offering->id, 'title' => 'Draft quiz', 'type' => 'quiz', 'is_published' => false]);
    $question = $quiz->questions()->create(['type' => 'short_answer', 'question_text' => 'Original question', 'points' => 2, 'order' => 1, 'correct_answer' => ['answer']]);
    $page = Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('selectQuestion', $question->id)->set('selectedQuestionData.question_text', 'Working question');
    $page->call('persistWorkspaceDraft', ['selectedQuestionId' => $question->id, 'selectedQuestionData' => $page->get('selectedQuestionData')]);
    expect($question->fresh()->question_text)->toBe('Original question');
    Livewire::test(QuizBuilder::class, ['record' => $quiz->id])->call('resumeWorkspaceDraft')->assertSet('selectedQuestionData.question_text', 'Working question')->call('saveQuestionProperties')->assertHasNoErrors();
    expect($question->fresh()->question_text)->toBe('Working question')->and(WorkspaceDraft::sole()->payload)->toBe([]);
});

it('prunes only expired drafts while preserving saved records and current working copies', function () {
    $page = Livewire::test(CreateSection::class)->fillForm(['name' => 'Current draft']);
    $page->call('persistWorkspaceDraft', ['data' => $page->get('data')]);
    $expired = WorkspaceDraft::sole()->replicate();
    $expired->context = hash('sha256', 'expired context');
    $expired->expires_at = now()->subMinute();
    $expired->save();
    $this->artisan('workspace-drafts:prune')->assertSuccessful();
    expect(WorkspaceDraft::count())->toBe(1)->and(WorkspaceDraft::sole()->payload['data']['name'])->toBe('Current draft')
        ->and($this->section->fresh())->not->toBeNull();
});
