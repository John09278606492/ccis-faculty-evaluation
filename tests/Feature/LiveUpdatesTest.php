<?php

use App\Events\BellNotificationsChanged;
use App\Events\PanelDataChanged;
use App\Livewire\WorkspaceContext;
use App\Models\Department;
use App\Models\EvaluationCycle;
use App\Models\User;
use App\Notifications\BellNotification;
use App\Services\ClassroomUpdates;
use App\Services\StudentAcademicRecords;
use App\Services\StudentSignIns;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

it('tells open pages once per kind when shared records change', function () {
    ['offering' => $offering] = evaluationFixture();
    ClassroomUpdates::sendPanel(); // the fixture's own records
    Event::fake([PanelDataChanged::class]);

    Department::create(['campus_id' => $offering->course->department->campus_id, 'name' => 'Engineering', 'code' => 'ENG', 'is_active' => true]);
    Department::create(['campus_id' => $offering->course->department->campus_id, 'name' => 'Nursing', 'code' => 'NUR', 'is_active' => true]);
    ClassroomUpdates::sendPanel();

    Event::assertDispatchedTimes(PanelDataChanged::class, 1);
    Event::assertDispatched(PanelDataChanged::class, fn (PanelDataChanged $event) => $event->kind === 'setup'
        && ! $event->staffOnly && $event->broadcastOn()[0]->name === 'private-testing.panel');
});

it('keeps staff-only changes off the student channel', function () {
    ['student' => $student] = evaluationFixture();
    ClassroomUpdates::sendPanel();
    Event::fake([PanelDataChanged::class]);

    $student->update(['name' => 'Renamed Student']);
    ClassroomUpdates::sendPanel();

    Event::assertDispatched(PanelDataChanged::class, fn (PanelDataChanged $event) => $event->kind === 'users'
        && $event->broadcastOn()[0]->name === 'private-testing.panel.staff');

    $channels = (new ReflectionProperty(Broadcast::driver(), 'channels'))->getValue(Broadcast::driver());
    expect($channels['{environment}.panel.staff']($student, 'testing'))->toBeFalse()
        ->and($channels['{environment}.panel']($student, 'testing'))->toBeTrue();
});

it('updates a submitter own status and counters across tabs without sharing their answers or identity', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    ClassroomUpdates::sendPanel();
    Event::fake([PanelDataChanged::class]);
    $this->actingAs($student)->post(route('evaluate.store', $offering), ['fields' => ['teaching_quality' => 5]])
        ->assertRedirect(route('evaluate.success', $offering));
    Event::assertDispatched(PanelDataChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$student->id
        && $event->broadcastWith() === ['kind' => 'evaluation']);
    $channels = (new ReflectionProperty(Broadcast::driver(), 'channels'))->getValue(Broadcast::driver());
    expect($channels['App.Models.User.{id}']($student, $student->id))->toBeTrue()
        ->and($channels['App.Models.User.{id}']($student, $student->id + 1))->toBeFalse();
});

it('shows bell notifications at once without a queue worker', function () {
    ['student' => $student] = evaluationFixture();
    config(['queue.default' => 'database']);
    Event::fake([BellNotificationsChanged::class]);

    BellNotification::make()->title('Quiz opens soon')->sendToDatabase($student);

    expect($student->notifications()->count())->toBe(1);
    Event::assertDispatched(BellNotificationsChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$student->id);
});

it('fills in empty live updates keys and keeps existing ones', function () {
    $env = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($env, "APP_NAME=CCIS\nREVERB_APP_ID=ccis-classroom\nREVERB_APP_KEY=\nREVERB_APP_SECRET=\n");

    $this->artisan('classroom:live-keys', ['--env-file' => $env])->assertSuccessful();
    $first = file_get_contents($env);
    $this->artisan('classroom:live-keys', ['--env-file' => $env])->assertSuccessful();

    expect($first)->toMatch('/^REVERB_APP_KEY=[a-z0-9]{20}$/m')
        ->toMatch('/^REVERB_APP_SECRET=[a-z0-9]{32}$/m')
        ->toContain('REVERB_APP_ID=ccis-classroom')
        ->and(file_get_contents($env))->toBe($first);
    unlink($env);
});

it('broadcasts cycle changes and bulk subject replacements even when student profile data is unchanged', function () {
    ['offering' => $offering, 'student' => $student] = evaluationFixture();
    ClassroomUpdates::sendPanel();
    Event::fake([PanelDataChanged::class]);
    EvaluationCycle::create(['campus_id' => $offering->campus->id, 'department_id' => $offering->course->department_id,
        'semester_id' => $offering->semester_id, 'name' => 'Live cycle']);
    app(StudentAcademicRecords::class)->save($student, $offering->semester_id, ['subjects' => [['subject_code' => 'CS101', 'description' => 'New subject title', 'units' => 3]]]);
    ClassroomUpdates::sendPanel();
    Event::assertDispatched(PanelDataChanged::class, fn ($e) => $e->kind === 'setup');
    Event::assertDispatched(PanelDataChanged::class, fn ($e) => $e->kind === 'users' && $e->staffOnly);
    Event::assertDispatchedTimes(PanelDataChanged::class, 2);
});

it('does not publish changes for a rolled back import transaction', function () {
    ['offering' => $offering] = evaluationFixture();
    ClassroomUpdates::sendPanel();
    Event::fake([PanelDataChanged::class]);
    try {
        DB::transaction(function () use ($offering) {
            Department::create(['campus_id' => $offering->campus->id, 'code' => 'ROLLBACK', 'name' => 'Rolled back', 'is_active' => true]);
            throw new RuntimeException('Reject import');
        });
    } catch (RuntimeException) {
    }
    ClassroomUpdates::sendPanel();
    Event::assertNotDispatched(PanelDataChanged::class);
});

it('refreshes the academic context after the first period is configured without changing a valid user selection', function () {
    ['offering' => $offering] = evaluationFixture();
    $actor = User::factory()->create(['is_student' => false]);
    $actor->assignRole('super_admin');
    $this->actingAs($actor);
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Filament\Facades\Filament::setTenant($offering->campus, isQuiet: true);
    $period = $offering->semester;
    $period->update(['is_active' => false]);
    $component = Livewire\Livewire::test(WorkspaceContext::class)->assertSet('semesterId', '');
    $period->update(['is_active' => true]);
    $component->call('$refresh')->assertSet('semesterId', (string) $period->id)->assertSee($period->name);
});

it('tells staff pages when a student starts waiting for sign-in approval', function () {
    ['student' => $student] = evaluationFixture();
    ClassroomUpdates::sendPanel();
    Event::fake([PanelDataChanged::class]);

    StudentSignIns::request($student);
    ClassroomUpdates::sendPanel();

    Event::assertDispatched(PanelDataChanged::class, fn (PanelDataChanged $event) => $event->kind === 'signins' && $event->staffOnly);
});
