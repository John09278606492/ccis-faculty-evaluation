<?php

use App\Http\Controllers\BackgroundTaskDownloadController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\EvaluationCopiesController;
use App\Http\Controllers\EvaluationEntryController;
use App\Http\Controllers\EvaluationTabularReportController;
use App\Http\Controllers\FacultyReportController;
use App\Http\Controllers\QuizSubmissionController;
use App\Http\Controllers\SectionStudentTemplateController;
use App\Http\Controllers\StudentDatabaseController;
use App\Http\Controllers\StudentSignInController;
use App\Http\Middleware\ResolveEvaluationStudent;
use Illuminate\Support\Facades\Route;

Route::get('/background-downloads/{task}', BackgroundTaskDownloadController::class)->middleware('auth')->name('background.download');
Route::get('/section-student-template/{section}', SectionStudentTemplateController::class)->middleware('auth')->name('sections.template');

Route::view('/student/pin-pending', 'auth.pin-pending')->name('student.pin-pending');
Route::get('/evaluation-copies/{token}', EvaluationCopiesController::class)->middleware(['auth', 'signed:identity'])->name('evaluation.copies');
Route::post('/student/sign-in/check', [StudentSignInController::class, 'check'])
    ->middleware('throttle:60,1')->name('student.sign-in.check');

// Route::get('/', function () {
//     return view('welcome');
// });

// Evaluation routes for students to evaluate instructors
Route::get('/evaluations/start', [EvaluationEntryController::class, 'show'])->name('evaluation.entry');
Route::post('/evaluations/start', [EvaluationEntryController::class, 'identify'])->middleware('throttle:evaluation-identify')->name('evaluation.identify');
Route::post('/evaluations/leave', [EvaluationEntryController::class, 'leave'])->name('evaluation.leave');
// Each evaluator has a separate request budget, including behind a shared campus IP.
Route::middleware([ResolveEvaluationStudent::class, 'throttle:evaluation-access'])->group(function () {
    Route::get('/evaluate/{courseOffering}', [EvaluationController::class, 'show'])
        ->name('evaluate.course-offering');
    Route::post('/evaluate/{courseOffering}', [EvaluationController::class, 'store'])
        ->name('evaluate.store');
    Route::get('/evaluate/{courseOffering}/success', [EvaluationController::class, 'success'])
        ->name('evaluate.success');
});

// Form preview route (for admins)
Route::get('/evaluation-reports/{offering}', [FacultyReportController::class, 'show'])->middleware('auth')->name('evaluation.report');
Route::get('/evaluation-tabular-reports/{offering}/{kind}', EvaluationTabularReportController::class)
    ->whereIn('kind', ['scores', 'tally'])->middleware('auth')->name('evaluation.tabular-report');
Route::post('/evaluation-reports/{offering}', [FacultyReportController::class, 'save'])->middleware('auth')->name('evaluation.report.save');
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
