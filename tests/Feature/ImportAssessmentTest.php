<?php

use App\Models\Quiz;

function assessmentDefinitionFile(): string
{
    $path = storage_path('framework/testing/assessment-'.uniqid().'.json');
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, json_encode([
        'title' => 'Final Exam',
        'type' => 'exam',
        'duration_minutes' => 165,
        'instructions' => "Part I first.\nThen Part II.",
        'questions' => [
            ['type' => 'multiple_choice', 'question_text' => 'Which function makes JSON?', 'points' => 1,
                'options' => [['key' => 'a', 'label' => 'json_decode()'], ['key' => 'b', 'label' => 'json_encode()']], 'correct_answer' => ['b']],
            ['type' => 'code', 'question_text' => "Build the library.\nTask A", 'points' => 70, 'options' => [
                'language' => 'php', 'allow_run' => false, 'database' => ['enabled' => true, 'starter_sql' => ''],
                'files' => [['name' => 'index.php', 'content' => '<!DOCTYPE html>'], ['name' => 'connection.php', 'content' => '<?php']],
            ], 'correct_answer' => ['files' => [['name' => 'index.php', 'content' => '<h1>Library</h1>'], ['name' => 'connection.php', 'content' => '<?php $conn = 1;']]]],
        ],
    ]));

    return $path;
}

afterEach(fn () => array_map('unlink', glob(storage_path('framework/testing/assessment-*.json'))));

it('lists the matching classes and adds nothing without a chosen class', function () {
    ['offering' => $offering] = evaluationFixture();
    $offering->update(['section' => 'BSIT 3C']);

    $this->artisan('classroom:import-assessment', ['file' => assessmentDefinitionFile(), '--find' => '3C'])
        ->expectsOutputToContain('BSIT 3C')
        ->assertFailed();

    expect(Quiz::count())->toBe(0);
});

it('adds a prepared exam to the class as an unpublished draft that can be published', function () {
    ['offering' => $offering] = evaluationFixture();
    $file = assessmentDefinitionFile();

    $this->artisan('classroom:import-assessment', ['file' => $file, '--offering' => $offering->id])
        ->expectsOutputToContain('2 questions, 71 points')
        ->doesntExpectOutputToContain('Before publishing')
        ->assertSuccessful();

    $quiz = Quiz::sole();
    expect($quiz->is_published)->toBeFalse()
        ->and($quiz->type)->toBe('exam')
        ->and($quiz->duration_minutes)->toBe(165)
        ->and($quiz->results_visibility)->toBe('manual')
        ->and($quiz->questions->pluck('order')->all())->toBe([1, 2])
        ->and($quiz->questions[1]->hasDatabase())->toBeTrue()
        ->and($quiz->questions[1]->studentsCanRunCode())->toBeFalse()
        ->and($quiz->questions[1]->referenceFiles()[0]['content'])->toBe('<h1>Library</h1>');

    $quiz->update(['is_published' => true]);
    expect($quiz->refresh()->is_published)->toBeTrue();

    $this->artisan('classroom:import-assessment', ['file' => $file, '--offering' => $offering->id])
        ->expectsOutputToContain('already has an assessment')
        ->assertFailed();
    expect(Quiz::count())->toBe(1);
});

it('shows expected-output pictures under a question', function () {
    ['offering' => $offering] = evaluationFixture();
    $this->artisan('classroom:import-assessment', ['file' => assessmentDefinitionFile(), '--offering' => $offering->id])->assertSuccessful();
    $quiz = Quiz::sole();
    @mkdir(public_path('exam-images/testing'), 0777, true);
    file_put_contents(public_path('exam-images/testing/list.png'), 'png');
    try {
        $this->artisan('classroom:set-expected-output', ['quiz' => $quiz->id, 'question' => 2, 'images' => ['exam-images/testing/list.png::The book list', 'exam-images/testing/missing.png']])
            ->expectsOutputToContain('was not found')->assertFailed();
        $this->artisan('classroom:set-expected-output', ['quiz' => $quiz->id, 'question' => 2, 'images' => ['exam-images/testing/list.png::The book list']])
            ->assertSuccessful();
        $question = $quiz->questions()->where('order', 2)->first();
        expect($question->expectedOutputImages())->toBe([['url' => asset('exam-images/testing/list.png'), 'caption' => 'The book list']])
            ->and($question->hasDatabase())->toBeTrue();
    } finally {
        @unlink(public_path('exam-images/testing/list.png'));
        @rmdir(public_path('exam-images/testing'));
    }
});
