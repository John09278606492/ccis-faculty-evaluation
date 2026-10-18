<?php

use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\QuizSubmissionController;
use App\Http\Controllers\StudentDatabaseController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('welcome');
// });

// Evaluation routes for students to evaluate instructors
// Rate limited to prevent abuse (10 requests per minute)
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/evaluate/{courseOffering}', [EvaluationController::class, 'show'])
        ->name('evaluate.course-offering');
    Route::post('/evaluate/{courseOffering}', [EvaluationController::class, 'store'])
        ->name('evaluate.store');
    Route::get('/evaluate/{courseOffering}/success', [EvaluationController::class, 'success'])
        ->name('evaluate.success');
});

// Form preview route (for admins)
Route::get('/form-preview/{form}', [EvaluationController::class, 'preview'])
    ->name('form.preview')
    ->middleware('auth');

Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::get('/change-password', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::post('/change-password', [ChangePasswordController::class, 'update']);
});

Route::get('/quiz-submissions/{quizAnswer}/download', [QuizSubmissionController::class, 'download'])
    ->name('quiz-submissions.download')
    ->middleware(['auth', 'throttle:30,1']);

Route::get('/student-databases/{quizAnswer}/open', [StudentDatabaseController::class, 'open'])
    ->name('student-databases.open')
    ->middleware(['auth', 'throttle:20,1']);

// Assessments used to live under quiz URLs; keep links students already have working.
$toAssessments = fn (string $path) => redirect($path.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301);
Route::get('/{tenant}/take-quiz/{quiz}', fn (string $tenant, string $quiz) => $toAssessments("/{$tenant}/take-assessment/{$quiz}"));
Route::get('/{tenant}/my-quizzes', fn (string $tenant) => $toAssessments("/{$tenant}/my-assessments"));
Route::get('/{tenant}/quizzes/{rest?}', fn (string $tenant, ?string $rest = null) => $toAssessments("/{$tenant}/assessments".($rest ? "/{$rest}" : '')))
    ->where('rest', '.*');
