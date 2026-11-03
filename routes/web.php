<?php

use App\Http\Controllers\BackgroundTaskDownloadController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\EvaluationCopiesController;
use App\Http\Controllers\EvaluationEntryController;
use App\Http\Controllers\EvaluationTabularReportController;
use App\Http\Controllers\FacultyReportController;
use App\Http\Controllers\InstructorOfferingTemplateController;
use App\Http\Controllers\QuizSubmissionController;
use App\Http\Controllers\SectionStudentTemplateController;
use App\Http\Controllers\StudentDatabaseController;
use App\Http\Controllers\StudentSignInController;
use App\Http\Middleware\ResolveEvaluationStudent;
use Illuminate\Support\Facades\Route;

// The admin panel (Filament) registers its own routes under /{tenant}. These are the pages outside it.

/*
| Sign-in and account
*/
Route::view('/student/pin-pending', 'auth.pin-pending')->name('student.pin-pending');
Route::post('/student/sign-in/check', [StudentSignInController::class, 'check'])
    ->middleware('throttle:60,1')->name('student.sign-in.check');

Route::middleware(['auth', 'throttle:10,1'])->group(function () {
    Route::get('/change-password', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::post('/change-password', [ChangePasswordController::class, 'update']);
});

/*
| Student faculty evaluation (Annex A)
*/
Route::get('/evaluations/start', [EvaluationEntryController::class, 'show'])->name('evaluation.entry');
Route::post('/evaluations/start', [EvaluationEntryController::class, 'identify'])->middleware('throttle:evaluation-identify')->name('evaluation.identify');
Route::post('/evaluations/leave', [EvaluationEntryController::class, 'leave'])->name('evaluation.leave');
// Each evaluator has a separate request budget, including behind a shared campus IP.
Route::middleware([ResolveEvaluationStudent::class, 'throttle:evaluation-access'])->group(function () {
    Route::get('/evaluate/{courseOffering}', [EvaluationController::class, 'show'])->name('evaluate.course-offering');
    Route::post('/evaluate/{courseOffering}', [EvaluationController::class, 'store'])->name('evaluate.store');
    Route::get('/evaluate/{courseOffering}/success', [EvaluationController::class, 'success'])->name('evaluate.success');
});

/*
| Staff pages opened from the panel: form previews, reports, printouts and downloads
*/
Route::middleware('auth')->group(function () {
    Route::get('/form-preview/{form}', [EvaluationController::class, 'preview'])->name('form.preview');

    Route::get('/evaluation-reports/{offering}', [FacultyReportController::class, 'show'])->name('evaluation.report');
    Route::post('/evaluation-reports/{offering}', [FacultyReportController::class, 'save'])->name('evaluation.report.save');
    Route::get('/evaluation-tabular-reports/{offering}/{kind}', EvaluationTabularReportController::class)
        ->whereIn('kind', ['scores', 'tally'])->name('evaluation.tabular-report');
    Route::get('/evaluation-copies/{token}', EvaluationCopiesController::class)->middleware('signed:identity')->name('evaluation.copies');

    Route::get('/background-downloads/{task}', BackgroundTaskDownloadController::class)->name('background.download');
    Route::get('/section-student-template/{section}', SectionStudentTemplateController::class)->name('sections.template');
    Route::get('/instructor-offerings-template/{campus}', InstructorOfferingTemplateController::class)->name('instructor-offerings.template');
});

/*
| Assessment files
*/
Route::middleware('auth')->group(function () {
    Route::get('/quiz-submissions/{quizAnswer}/download', [QuizSubmissionController::class, 'download'])
        ->middleware('throttle:30,1')->name('quiz-submissions.download');
    Route::get('/student-databases/{quizAnswer}/open', [StudentDatabaseController::class, 'open'])
        ->middleware('throttle:20,1')->name('student-databases.open');
});

/*
| Old links
| Assessments used to live under quiz URLs; keep links students already have working.
*/
$toAssessments = fn (string $path) => redirect($path.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301);
Route::get('/{tenant}/take-quiz/{quiz}', fn (string $tenant, string $quiz) => $toAssessments("/{$tenant}/take-assessment/{$quiz}"));
Route::get('/{tenant}/my-quizzes', fn (string $tenant) => $toAssessments("/{$tenant}/my-assessments"));
Route::get('/{tenant}/quizzes/{rest?}', fn (string $tenant, ?string $rest = null) => $toAssessments("/{$tenant}/assessments".($rest ? "/{$rest}" : '')))
    ->where('rest', '.*');
