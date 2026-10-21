<?php

use App\Filament\Pages\MyEvaluationResults;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\EvaluationsRelationManager;
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
    $admin = User::factory()->create();

    expect(EvaluationResultsRelease::canSeeScores($admin, $offering))->toBeTrue()
        ->and(EvaluationResultsRelease::canSeeScores($offering->evaluatee, $offering))->toBeFalse();

    $this->actingAs($offering->evaluatee);
    expect(EvaluationsRelationManager::canViewForRecord($offering, EditCourseOffering::class))->toBeFalse();
});
