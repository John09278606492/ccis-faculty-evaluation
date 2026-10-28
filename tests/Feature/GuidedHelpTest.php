<?php

use App\Livewire\GuidedTours;
use App\Models\BackgroundTask;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\GuidedHelp;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->offering] = evaluationFixture(false);
    $this->actor = $this->offering->instructor;
    $this->campus = $this->offering->campus;
    $this->actor->campuses()->syncWithoutDetaching([$this->campus->id]);
    $this->actor->assignRole(Role::findOrCreate('guide_coordinator', 'web'));
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students'] as $name) {
        $this->actor->givePermissionTo(Permission::findOrCreate($name, 'web'));
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->actingAs($this->actor);
});

it('maps campus routes to appropriate permitted guides', function () {
    $help = app(GuidedHelp::class);
    expect($help->forRoute('filament.admin.resources.academic-students.index'))->toBe('master-list')
        ->and($help->forRoute('filament.admin.resources.sections.view'))->toBe('section-roster')
        ->and($help->forRoute('filament.admin.pages.background-tasks'))->toBe('background')
        ->and($help->forRoute('filament.admin.auth.login'))->toBeNull();
});

it('keeps upload guidance behind import permissions', function () {
    $help = app(GuidedHelp::class);
    expect($help->allowed('master-upload'))->toBeTrue();
    $this->actor->revokePermissionTo('import_academic_students');
    expect($help->allowed('master-list'))->toBeTrue()
        ->and($help->allowed('master-upload'))->toBeFalse()
        ->and($help->allowed('section-upload'))->toBeFalse();
    Livewire::test(GuidedTours::class)->call('startGuide', 'master-upload')->assertForbidden();
});

it('cannot request a staff upload guide as a student or unrelated campus user', function () {
    $this->actingAs($this->student);
    expect(app(GuidedHelp::class)->allowed('master-list'))->toBeFalse();
    Livewire::test(GuidedTours::class)->call('startGuide', 'master-upload')->assertForbidden();
    $stranger = User::factory()->create();
    $stranger->givePermissionTo(Permission::findOrCreate('view_academic_students', 'web'));
    $this->actingAs($stranger);
    expect(app(GuidedHelp::class)->allowed('master-list'))->toBeFalse();
    Livewire::test(GuidedTours::class)->call('startGuide', 'workspace')->assertForbidden();
});

it('opens a replayable manual tour without changing records or mounting resource pages', function () {
    $counts = [User::count(), BackgroundTask::count(), FormSubmission::count()];
    Livewire::test(GuidedTours::class)
        ->call('load')->assertSet('tours', [])
        ->call('startGuide', 'master-upload')
        ->assertDispatched('filament-tour::loaded-elements')
        ->assertDispatched('filament-tour::open-tour', id: 'campus-master-upload')
        ->assertSet('tours.0.route', '/__manual_campus_guide__')
        ->call('startGuide', 'master-upload')
        ->assertCount('tours', 1);
    expect([User::count(), BackgroundTask::count(), FormSubmission::count()])->toBe($counts);
});

it('never generates side effect events in tour steps', function () {
    $tour = app(GuidedHelp::class)->tour('section-upload');
    expect($tour->hasDisabledEvents())->toBeTrue()->and($tour->getSteps())->not->toBeEmpty();
    $content = collect($tour->getSteps())->map(fn ($step) => $step->getDescription())->join(' ');
    expect($content)->toContain('optional', 'confirm');
    Livewire::test(GuidedTours::class)->call('startGuide', 'does-not-exist')->assertForbidden();
});

it('anchors introductory and explanatory steps instead of creating centered modals', function () {
    foreach (['master-list', 'master-upload', 'sections', 'section-roster', 'section-upload', 'background'] as $context) {
        foreach (app(GuidedHelp::class)->tour($context)->getSteps() as $step) {
            expect($step->getElement())->toBeString()->not->toBeEmpty();
        }
    }
});

it('keeps page help available when the header is refreshed by Livewire', function () {
    $original = request();
    $request = Request::create('/livewire/update', 'POST', [
        'components' => [['snapshot' => json_encode(['memo' => ['path' => $this->campus->id.'/academic-students']])]],
    ], server: ['HTTP_X_LIVEWIRE' => 'true']);
    app()->instance('request', $request);

    try {
        expect(app(GuidedHelp::class)->forCurrentPage())->toBe('master-list');
        $this->actor->revokePermissionTo('view_academic_students');
        expect(app(GuidedHelp::class)->forCurrentPage())->toBeNull();
    } finally {
        app()->instance('request', $original);
    }
});
