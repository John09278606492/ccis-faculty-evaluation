<?php

use App\Filament\Pages\EvaluationResults;
use App\Models\FormSubmission;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['offering' => $this->offering, 'form' => $this->form] = evaluationFixture();
    $this->staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->offering->course->department_id]);
    $this->staff->campuses()->attach($this->offering->campus);
    foreach (['page_EvaluationResults', 'update_course::offering'] as $permission) {
        $this->staff->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->staff);
});

it('lets faculty read only their own released report with the personal-results permission', function () {
    config(['evaluations.min_responses_for_results' => 1]);
    $faculty = $this->offering->evaluatee;
    $faculty->campuses()->syncWithoutDetaching([$this->offering->campus->id]);
    $faculty->assignRole(Role::findOrCreate('instructor', 'web'));
    $faculty->givePermissionTo(Permission::findOrCreate('page_MyEvaluationResults', 'web'));
    $faculty->givePermissionTo('update_course::offering');
    FormSubmission::create(['form_id' => $this->form->id, 'course_offering_id' => $this->offering->id, 'data' => ['teaching_quality' => 4]]);
    $this->actingAs($faculty)->get(route('evaluation.report', $this->offering))->assertForbidden();
    $this->form->update(['ends_at' => now()->subMinute()]);
    $released = $this->get(route('evaluation.report', $this->offering))->assertOk()->assertSee('80.00');
    expect($released->viewData('canEdit'))->toBeFalse()->and($released->viewData('details'))->not->toHaveKey('prepared_id');
    $this->post(route('evaluation.report.save', $this->offering), ['prepared_name' => 'Forged'])->assertForbidden();
    $other = User::factory()->create(['is_student' => false, 'instructor_department_id' => $faculty->instructor_department_id]);
    $other->campuses()->attach($this->offering->campus);
    $other->givePermissionTo('page_MyEvaluationResults');
    $this->actingAs($other)->get(route('evaluation.report', $this->offering))->assertForbidden();
});

it('renders Annex C with weighted student ratings and anonymous qualitative comments', function () {
    FormSubmission::create(['form_id' => $this->form->id, 'course_offering_id' => $this->offering->id, 'data' => ['teaching_quality' => 4, '_overall_comment' => 'Clear examples in class.']]);
    FormSubmission::create(['form_id' => $this->form->id, 'course_offering_id' => $this->offering->id, 'data' => ['teaching_quality' => 5, '_field_comments' => ['teaching_quality' => '<script>alert(1)</script>']]]);
    $response = $this->get(route('evaluation.report', $this->offering));
    $response->assertOk()->assertSee('INDIVIDUAL FACULTY EVALUATION REPORT')->assertSee('Summary of Average SET Rating')
        ->assertSee('90.00')->assertSee('180.00')->assertSee('Clear examples in class.')->assertSee('OVERALL RATING')->assertDontSee('Not configured')
        ->assertDontSee('<script>alert(1)</script>', false)->assertSee('Prepared by')->assertSee('Reviewed by');
    expect($response->viewData('ratings')['SET'])->toBe(90.0);
});

it('preserves acknowledgement details when individual report metadata is saved', function () {
    $this->post(route('evaluation.report.save', $this->offering), ['annex' => 'D', 'action_plan' => 'Mentoring plan']);
    $this->post(route('evaluation.report.save', $this->offering), ['annex' => 'C', 'prepared_name' => 'Report Staff', 'rank' => 'Assistant Professor'])
        ->assertRedirect(route('evaluation.report', ['offering' => $this->offering, 'annex' => 'C']));
    $this->get(route('evaluation.report', $this->offering))->assertSee('Report Staff')->assertSee('Assistant Professor');
    $this->get(route('evaluation.report', ['offering' => $this->offering, 'annex' => 'D']))->assertSee('Mentoring plan');
});

it('opens the report inside an authorized modal without a navigation URL', function () {
    FormSubmission::create(['form_id' => $this->form->id, 'course_offering_id' => $this->offering->id, 'data' => ['teaching_quality' => 4]]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->offering->campus, isQuiet: true);
    $component = Livewire::test(EvaluationResults::class);
    expect($component->instance()->getTable()->getAction('view_details')->getUrl())->toBeNull();
    $component->mountTableAction('view_details', $this->offering->evaluatee)
        ->assertSee('Annex C')->assertSee('Annex D')->assertSee('<iframe', false);
});

it('shows department heads and allows swapping report signatories without changing evaluation authority', function () {
    $role = Role::findOrCreate('staff', 'web');
    $this->staff->assignRole($role);
    $head = User::factory()->create(['is_student' => false]);
    $head->assignRole($role);
    $head->campuses()->attach($this->offering->campus);
    $department = $this->offering->course->department;
    $department->update(['supervisor_id' => $head->id, 'chairperson_id' => $head->id]);
    $response = $this->get(route('evaluation.report', $this->offering))->assertOk()->assertSee('Department Leadership')->assertSee($head->name);
    expect($response->viewData('details')['reviewed_id'])->toBe($head->id);
    $this->post(route('evaluation.report.save', $this->offering), ['annex' => 'C', 'prepared_id' => $head->id, 'reviewed_id' => $this->staff->id, 'prepared_name' => 'Forged name'])->assertRedirect();
    $details = $this->get(route('evaluation.report', $this->offering))->viewData('details');
    expect($details['prepared_name'])->toBe($head->name)->and($details['reviewed_name'])->toBe($this->staff->name)
        ->and($department->fresh()->supervisor_id)->toBe($head->id);
    $foreign = User::factory()->create(['is_student' => false]);
    $foreign->assignRole($role);
    $this->post(route('evaluation.report.save', $this->offering), ['prepared_id' => $foreign->id])->assertSessionHasErrors('prepared_id');
});
