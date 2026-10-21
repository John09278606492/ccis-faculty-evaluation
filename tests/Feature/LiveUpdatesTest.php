<?php

use App\Events\BellNotificationsChanged;
use App\Events\PanelDataChanged;
use App\Models\Department;
use App\Notifications\BellNotification;
use App\Services\ClassroomUpdates;
use Illuminate\Support\Facades\Broadcast;
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
