<?php

use App\Events\BellNotificationsChanged;
use App\Livewire\DatabaseNotifications;
use App\Models\User;
use App\Services\DatabaseNotificationActions;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Event::fake([BellNotificationsChanged::class]);
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->originalConnection = DB::getDefaultConnection();
    $this->sqliteFile = Storage::disk('local')->path('bell-test.sqlite');
    touch($this->sqliteFile);
    config(['database.connections.bell_test' => ['driver' => 'sqlite', 'database' => $this->sqliteFile,
        'prefix' => '', 'journal_mode' => 'WAL', 'busy_timeout' => 100, 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('bell_test');
    $this->owner->setConnection('bell_test');
    $this->other->setConnection('bell_test');
    Schema::create('notifications', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
    $this->notice = function (User $user, string $title = 'Saved notification') {
        $id = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $id, 'type' => 'test', 'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id, 'data' => json_encode(['format' => 'filament', 'title' => $title]),
            'created_at' => now(), 'updated_at' => now()]);

        return $id;
    };
    $this->holdWriter = function () {
        $writer = new PDO('sqlite:'.$this->sqliteFile);
        $writer->exec('BEGIN IMMEDIATE');

        return $writer;
    };
    $this->actions = app(DatabaseNotificationActions::class);
});

afterEach(function () {
    DB::disconnect('notification_actions');
    DB::purge('bell_test');
    DB::setDefaultConnection($this->originalConnection);
});

it('ignores toast closes and foreign IDs without requesting a write while another writer holds SQLite', function () {
    $foreignId = ($this->notice)($this->other);
    $writer = ($this->holdWriter)();
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^(delete|insert|update)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    try {
        $started = microtime(true);
        for ($i = 0; $i < 15; $i++) {
            $this->actions->record($this->owner->id, 'dismiss', (string) Str::uuid());
            $this->actions->record($this->owner->id, 'dismiss', $foreignId);
        }
        expect($writes)->toBe([])->and($this->actions->pending($this->owner->id))->toBe([])
            ->and(microtime(true) - $started)->toBeLessThan(1.0);
    } finally {
        $writer->exec('ROLLBACK');
    }
    expect(DB::table('notifications')->where('id', $foreignId)->exists())->toBeTrue();
});

it('keeps actual bell actions responsive and durable through a reload during a concurrent import lock', function () {
    $read = ($this->notice)($this->owner, 'Keep this receipt');
    $dismiss = ($this->notice)($this->owner, 'Dismiss this receipt');
    $foreign = ($this->notice)($this->other, 'Another users receipt');
    $writer = ($this->holdWriter)();
    try {
        $started = microtime(true);
        $bell = Livewire::test(DatabaseNotifications::class)
            ->call('markNotificationAsRead', $read)->assertSee('Saving…')
            ->call('removeNotification', $dismiss)->assertDontSee('Dismiss this receipt');
        expect(microtime(true) - $started)->toBeLessThan(3.0);
        expect(DB::table('notifications')->where('id', $read)->value('read_at'))->toBeNull();
        expect(DB::table('notifications')->where('id', $dismiss)->exists())->toBeTrue();
        $reloaded = Livewire::test(DatabaseNotifications::class)->assertSee('Saving…')->assertSee('Keep this receipt')->assertDontSee('Dismiss this receipt');
        expect($reloaded->instance()->getUnreadNotificationsCount())->toBe(0);
        expect($this->actions->pending($this->owner->id))->toBe([$read => 'read', $dismiss => 'dismiss']);
    } finally {
        $writer->exec('ROLLBACK');
    }
    $reloaded->call('retryPendingActions')->assertDontSee('Saving…');
    expect(DB::table('notifications')->where('id', $read)->value('read_at'))->not->toBeNull()
        ->and(DB::table('notifications')->where('id', $dismiss)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('id', $foreign)->exists())->toBeTrue()
        ->and($this->actions->pending($this->owner->id))->toBe([]);
});

it('keeps the final read state and never revives a pending dismissal', function () {
    $id = ($this->notice)($this->owner);
    $writer = ($this->holdWriter)();
    try {
        $this->actions->record($this->owner->id, 'read', $id);
        $this->actions->record($this->owner->id, 'unread', $id);
        expect($this->actions->pending($this->owner->id))->toBe([$id => 'unread']);
        $this->actions->record($this->owner->id, 'dismiss', $id);
        $this->actions->record($this->owner->id, 'read', $id);
        expect($this->actions->pending($this->owner->id))->toBe([$id => 'dismiss']);
    } finally {
        $writer->exec('ROLLBACK');
    }
    $this->actions->flushAll();
    expect(DB::table('notifications')->count())->toBe(0);
});

it('applies clear all only to the users notifications present when clicked', function () {
    $old = ($this->notice)($this->owner);
    $foreign = ($this->notice)($this->other);
    $writer = ($this->holdWriter)();
    try {
        Livewire::test(DatabaseNotifications::class)->call('markAllNotificationsAsRead')->call('clearNotifications')->assertSee('Saving…');
    } finally {
        $writer->exec('ROLLBACK');
    }
    $new = ($this->notice)($this->owner, 'Import just completed');
    $this->actions->flushAll();
    expect(DB::table('notifications')->where('id', $old)->exists())->toBeFalse()
        ->and(DB::table('notifications')->where('id', $new)->exists())->toBeTrue()
        ->and(DB::table('notifications')->where('id', $foreign)->exists())->toBeTrue();
});
