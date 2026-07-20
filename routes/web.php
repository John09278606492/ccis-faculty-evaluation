<?php

use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\QuizSubmissionController;
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

Route::get('/quiz-submissions/{quizAnswer}/download', [QuizSubmissionController::class, 'download'])
    ->name('quiz-submissions.download')
    ->middleware(['auth', 'throttle:30,1']);
