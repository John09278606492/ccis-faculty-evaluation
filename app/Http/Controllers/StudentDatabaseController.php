<?php

namespace App\Http\Controllers;

use App\Models\QuizAnswer;
use App\Services\StudentDatabase\StudentDatabases;
use App\Services\StudentDatabase\StudentDbServer;
use App\Services\StudentDatabase\StudentProjectRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StudentDatabaseController extends Controller
{
    /**
     * Sign the student (or their instructor) in to phpMyAdmin with the student's
     * own MySQL account, opened on this answer's database.
     */
    public function open(Request $request, QuizAnswer $quizAnswer, StudentDatabases $databases, StudentDbServer $server): RedirectResponse
    {
        $quizAnswer->loadMissing(['question', 'attempt.quiz.courseOffering']);
        abort_unless($quizAnswer->question?->hasDatabase(), 404);

        $user = $request->user();
        $attempt = $quizAnswer->attempt;
        $isOwner = $attempt->user_id === $user->id;
        $managesQuiz = $user->isSuperAdmin()
            || ($user->can('update_quiz') && $attempt->quiz->courseOffering?->user_id === $user->id);

        if ($isOwner && ! $managesQuiz) {
            // Students work in their database only while their attempt is open; the
            // copy exported at submission is what gets graded.
            abort_unless($attempt->status === 'in_progress' && ! $attempt->isExpired(), 403, 'This attempt is closed, so its database is read by your instructor only.');
        } else {
            abort_unless($managesQuiz && $quizAnswer->database_name, 403);
        }
        abort_unless($databases->available(), 503, 'The student database server is not running. Ask your instructor to start it.');

        try {
            $workspace = $databases->workspace($quizAnswer);
        } catch (Throwable $error) {
            report($error);
            abort(503, 'Your database could not be opened. Try again in a moment, or ask your instructor to check the student database server.');
        }

        return redirect()->away($server->signOnUrl($workspace['user'], $workspace['password'], $workspace['database'], ['read_only' => $managesQuiz, 'expires' => ! $managesQuiz && $attempt->expires_at ? $attempt->expires_at->timestamp : PHP_INT_MAX]));
    }

    /**
     * Run the student's saved project files on the server with their own database,
     * while their attempt is open. Only the student who owns the attempt can run it.
     */
    public function run(Request $request, QuizAnswer $quizAnswer, StudentDatabases $databases, StudentProjectRunner $runner, string $path = ''): Response
    {
        $quizAnswer->loadMissing(['question', 'attempt']);
        abort_unless($quizAnswer->question?->hasDatabase(), 404);
        $attempt = $quizAnswer->attempt;
        abort_unless($attempt->user_id === $request->user()->id, 403);
        abort_unless($attempt->status === 'in_progress' && ! $attempt->isExpired(), 403, 'This attempt is closed, so its project can no longer be run.');

        if ($path === '') {
            $entry = $runner->entry($quizAnswer);
            abort_unless($entry, 404, 'Your project has no PHP or HTML page to open.');

            return redirect()->route('project-run', ['quizAnswer' => $quizAnswer, 'path' => $entry]);
        }
        abort_unless($databases->available(), 503, 'The student database server is not running. Ask your instructor to start it.');

        // A page and its fetch() calls arrive together, so the workspace is prepared once per attempt.
        $key = 'project-run.'.$quizAnswer->id;
        try {
            $workspace = $request->session()->get($key) ?? $databases->workspace($quizAnswer);
        } catch (Throwable $error) {
            report($error);
            abort(503, 'Your database could not be opened. Try again in a moment.');
        }
        $request->session()->put($key, $workspace);

        return $runner->run($quizAnswer->refresh(), $path, $request, $workspace);
    }
}
