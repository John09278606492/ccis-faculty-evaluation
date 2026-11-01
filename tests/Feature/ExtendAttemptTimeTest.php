<?php

use App\Filament\Pages\TakeQuiz;
use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\AttemptsRelationManager;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\FinalizeAssessmentAttempt;
use Livewire\Livewire;

function examOnDevice(string $device, $quiz)
{
    return Livewire::withCookies([TakeQuiz::DEVICE_COOKIE => str_pad($device, 40, 'x')])
        ->test(TakeQuiz::class, ['quiz' => $quiz]);
}

it('reopens an exam closed by its time limit so the student continues at home with their answers', function () {
    extract(quizWorkflowFixture());
    $quiz->update(['type' => 'exam', 'max_attempts' => 1]);
    $this->actingAs($student);
    examOnDevice('campus', $quiz)->call('beginAttempt')->set('answers.'.$question->id, 'echo 1;');
    $attempt = QuizAttempt::sole();

    // Time ran out on campus and the sweep closed the attempt.
    $attempt->update(['expires_at' => now()->subDay()]);
    app(FinalizeAssessmentAttempt::class)->handle($attempt);
    expect($attempt->refresh()->finalized_reason)->toBe('expired');

    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->assertTableActionVisible('extendTime', $attempt)
        ->callTableAction('extendTime', $attempt, ['minutes' => 90, 'any_device' => true])
        ->assertHasNoTableActionErrors();
    $attempt->refresh();
    expect($attempt->status)->toBe('in_progress')
        ->and($attempt->submitted_at)->toBeNull()
        ->and($attempt->score)->toBeNull()
        ->and($attempt->device_hash)->toBeNull()
        ->and($attempt->expires_at->between(now()->addMinutes(89), now()->addMinutes(91)))->toBeTrue();

    // A different computer at home picks it up with the saved answer.
    $this->actingAs($student);
    examOnDevice('home', $quiz)->assertSet('accessDenied', false)->assertSet('reviewMode', false)
        ->assertSet('answers.'.$question->id, 'echo 1;');
    examOnDevice('campus', $quiz)->assertSet('accessDenied', true);
});

it('extends every unfinished attempt but leaves students who submitted themselves alone', function () {
    extract(quizWorkflowFixture());
    $quiz->update(['type' => 'exam']);
    $this->actingAs($student);
    examOnDevice('campus', $quiz)->call('beginAttempt');
    $open = QuizAttempt::sole();
    $endsAt = now()->addMinutes(10);
    $open->update(['expires_at' => $endsAt]);

    $other = User::factory()->create(['is_student' => true]);
    $submitted = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $other->id, 'attempt_number' => 1,
        'started_at' => now()->subHour(), 'status' => 'graded', 'finalized_reason' => 'submitted', 'submitted_at' => now(), 'score' => 1]);

    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->assertTableActionHidden('extendTime', $submitted)
        ->callTableAction('extendAll', data: ['minutes' => 30, 'any_device' => false, 'include_timed_out' => true])
        ->assertHasNoTableActionErrors();

    // Still-running time is extended from its current end, and the device lock stays.
    expect($open->refresh()->expires_at->timestamp)->toBe($endsAt->copy()->addMinutes(30)->timestamp)
        ->and($open->device_hash)->not->toBeNull()
        ->and($submitted->refresh()->status)->toBe('graded');
});
