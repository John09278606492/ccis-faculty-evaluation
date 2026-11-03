<?php

use App\Filament\Pages\EvaluationResults;
use App\Filament\Pages\MyEvaluationResults;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\EvaluationsRelationManager;
use App\Models\Campus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationResultsRelease;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function submitEvaluations($offering, $form, int $count): void
{
    foreach (range(1, $count) as $i) {
        FormSubmission::create([
            'form_id' => $form->id,
            'course_offering_id' => $offering->id,
            'submission_fingerprint' => "fp-{$offering->id}-{$i}",
            'data' => ['teaching_quality' => 5],
            'submitted_at' => now()->subMinutes($i),
        ]);
    }
}

function actingAsEvaluatee($offering): User
{
    $instructor = $offering->evaluatee;
    $instructor->campuses()->syncWithoutDetaching([$offering->campus->id]);
    $instructor->givePermissionTo(Permission::findOrCreate('page_MyEvaluationResults', 'web'));
    test()->actingAs($instructor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($offering->semester->schoolYear->campus, isQuiet: true);

    return $instructor;
}

it('hides an instructor\'s results while the evaluation is open', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    submitEvaluations($offering, $form, 6);
    actingAsEvaluatee($offering);

    expect(EvaluationResultsRelease::isReleased($offering))->toBeFalse();

    Livewire::test(MyEvaluationResults::class)
        ->assertCountTableRecords(0)
        ->assertSee('6 responses so far. Results open after the evaluation closes.');
});

it('hides results with fewer than five responses even after closing', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    submitEvaluations($offering, $form, 4);
    $form->update(['ends_at' => now()->subDay()]);
    actingAsEvaluatee($offering);

    Livewire::test(MyEvaluationResults::class)
        ->assertCountTableRecords(0)
        ->assertSee('Results are shown only when at least 5 students respond');
});

it('shows results without submission times once closed with five responses', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    submitEvaluations($offering, $form, 5);
    $form->update(['ends_at' => now()->subDay()]);
    actingAsEvaluatee($offering);

    Livewire::test(MyEvaluationResults::class)
        ->assertCountTableRecords(5)
        ->assertDontSee('Not open yet')
        ->assertTableColumnDoesNotExist('submitted_at');
});

it('lets admins review results the evaluatee cannot see yet', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    submitEvaluations($offering, $form, 1);
    $admin = User::factory()->create(['instructor_department_id' => $offering->course->department_id]);
    $admin->campuses()->attach($offering->campus);
    $admin->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));

    expect(EvaluationResultsRelease::canSeeScores($admin, $offering))->toBeTrue()
        ->and(EvaluationResultsRelease::canSeeScores($offering->evaluatee, $offering))->toBeFalse();

    $this->actingAs($offering->evaluatee);
    expect(EvaluationsRelationManager::canViewForRecord($offering, EditCourseOffering::class))->toBeFalse();
});

it('keeps another campus scores and course details out of the evaluation results page', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    submitEvaluations($offering, $form, 1);
    $otherCampus = Campus::create(['name' => 'Other campus', 'slug' => 'other', 'is_active' => true]);
    $year = $offering->semester->schoolYear->replicate();
    $year->campus_id = $otherCampus->id;
    $year->save();
    $semester = $offering->semester->replicate();
    $semester->school_year_id = $year->id;
    $semester->save();
    $other = $offering->replicate();
    $other->semester_id = $semester->id;
    $other->section = 'PRIVATE-OTHER-CAMPUS';
    $other->save();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $other->id, 'submission_fingerprint' => 'other-private', 'data' => ['teaching_quality' => 1], 'submitted_at' => now()]);
    $viewer = User::factory()->create();
    $viewer->assignRole('super_admin');
    $viewer->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->actingAs($viewer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($offering->campus, isQuiet: true);
    Livewire::test(EvaluationResults::class)
        ->assertTableColumnStateSet('evaluations_count', 1, $offering->evaluatee)
        ->assertTableColumnStateSet('overall_score', '5.00', $offering->evaluatee)
        ->callTableAction('view_details', $offering->evaluatee)
        ->assertDontSee('PRIVATE-OTHER-CAMPUS');
});

it('does not release a replacement instrument based on old or incomplete responses', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    submitEvaluations($offering, $form, 5);
    $replacement = Form::create(['name' => 'New version', 'is_active' => true, 'rating_scale' => [['value' => 5], ['value' => 1]], 'ends_at' => now()->subDay()]);
    $replacement->fields()->create(['name' => 'current_question', 'label' => 'Current question', 'type' => 'likert_scale', 'order' => 1]);
    $offering->update(['form_id' => $replacement->id]);
    foreach (range(1, 5) as $index) {
        FormSubmission::create(['course_offering_id' => $offering->id, 'form_id' => $replacement->id, 'data' => []]);
    }
    expect(EvaluationResultsRelease::completeResponses($offering))->toBe(0)
        ->and(EvaluationResultsRelease::isReleased($offering))->toBeFalse();
});

it('requires report authority and department scope when reviewing someone else results', function () {
    ['offering' => $offering] = evaluationFixture();
    $viewer = User::factory()->create(['is_student' => false]);
    $viewer->campuses()->attach($offering->campus);
    expect(EvaluationResultsRelease::canSeeScores($viewer, $offering))->toBeFalse();
    $viewer->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    expect(EvaluationResultsRelease::canSeeScores($viewer, $offering))->toBeFalse();
    $viewer->update(['instructor_department_id' => $offering->course->department_id]);
    expect(EvaluationResultsRelease::canSeeScores($viewer, $offering))->toBeTrue();
});
