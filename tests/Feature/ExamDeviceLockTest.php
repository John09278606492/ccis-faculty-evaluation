<?php

use App\Filament\Pages\TakeQuiz;
use App\Filament\Resources\QuizResource\Pages\EditQuiz;
use App\Filament\Resources\QuizResource\RelationManagers\AttemptsRelationManager;
use App\Models\QuizAttempt;
use Livewire\Livewire;

function examOn(string $device, $quiz)
{
    return Livewire::withCookies([TakeQuiz::DEVICE_COOKIE => str_pad($device, 40, 'x')])->test(TakeQuiz::class, ['quiz' => $quiz]);
}

it('keeps an exam on the browser that started it until the instructor allows another device', function () {
    extract(quizWorkflowFixture());
    $quiz->update(['type' => 'exam']);
    $this->actingAs($student);
    examOn('first', $quiz)->call('beginAttempt');
    $attempt = QuizAttempt::sole();
    expect($attempt->device_hash)->toBe(hash('sha256', str_pad('first', 40, 'x')));

    // A classmate signs in with the same ID and PIN on another computer.
    examOn('second', $quiz)->assertSet('accessDenied', true)->assertSee('already open on another device');
    examOn('second', $quiz)->assertSet('accessDenied', true);
    expect($attempt->refresh()->device_blocks)->toBe(1)
        ->and($attempt->blocked_device_hash)->toBe(hash('sha256', str_pad('second', 40, 'x')))
        ->and($instructor->notifications()->count())->toBe(1);

    // The first computer keeps working.
    examOn('first', $quiz)->assertSet('accessDenied', false)->set('answers.'.$question->id, 'echo')->assertHasNoErrors();
    expect($attempt->answers()->where('quiz_question_id', $question->id)->value('answer_text'))->toBe('echo');

    // The instructor moves the exam: the new device continues, the old one stops.
    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->assertTableColumnStateSet('device_blocks', 1, $attempt)
        ->callTableAction('allowNewDevice', $attempt);
    expect($attempt->refresh()->device_hash)->toBe(hash('sha256', str_pad('second', 40, 'x')))->and($attempt->blocked_device_hash)->toBeNull();

    $this->actingAs($student);
    examOn('first', $quiz)->assertSet('accessDenied', true);
    $second = examOn('second', $quiz)->assertSet('accessDenied', false)->assertSet('answers.'.$question->id, 'echo');

    // A page left open on a device the exam was moved away from stops saving.
    $attempt->update(['device_hash' => hash('sha256', str_pad('third', 40, 'x'))]);
    $second->set('answers.'.$question->id, 'changed')->assertStatus(409);
    expect($attempt->answers()->where('quiz_question_id', $question->id)->value('answer_text'))->toBe('echo');
});

it('does not tie quizzes to one device', function () {
    extract(quizWorkflowFixture());
    $this->actingAs($student);
    examOn('first', $quiz)->call('beginAttempt');
    examOn('second', $quiz)->assertSet('accessDenied', false);
    expect(QuizAttempt::sole()->device_hash)->toBeNull();
});

it('lets the instructor reset an attempt that was submitted by mistake', function () {
    extract(quizWorkflowFixture());
    $quiz->update(['type' => 'exam', 'max_attempts' => 1]);
    $this->actingAs($student);
    examOn('first', $quiz)->call('beginAttempt')->set('answers.'.$question->id, 'echo')->call('submit');
    $attempt = QuizAttempt::sole();
    expect($attempt->status)->not->toBe('in_progress');

    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->callTableAction('resetAttempt', $attempt)
        ->assertHasNoTableActionErrors();
    expect(QuizAttempt::count())->toBe(0)->and(\App\Models\QuizAnswer::count())->toBe(0);

    // The student starts again from the beginning, on any computer.
    $this->actingAs($student);
    examOn('second', $quiz)->assertSet('accessDenied', false)->call('beginAttempt');
    expect(QuizAttempt::sole())->attempt_number->toBe(1)->status->toBe('in_progress');
});

it('only offers reset on the latest attempt', function () {
    extract(quizWorkflowFixture());
    $quiz->update(['type' => 'quiz', 'max_attempts' => 2]);
    $first = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $student->id, 'attempt_number' => 1, 'status' => 'graded', 'started_at' => now(), 'submitted_at' => now()]);
    $second = QuizAttempt::create(['quiz_id' => $quiz->id, 'user_id' => $student->id, 'attempt_number' => 2, 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now()]);

    $this->actingAs($instructor);
    Livewire::test(AttemptsRelationManager::class, ['ownerRecord' => $quiz, 'pageClass' => EditQuiz::class])
        ->assertTableActionHidden('resetAttempt', $first)
        ->assertTableActionVisible('resetAttempt', $second);
});
