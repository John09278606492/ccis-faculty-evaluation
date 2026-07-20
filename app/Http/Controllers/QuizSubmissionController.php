<?php

namespace App\Http\Controllers;

use App\Models\QuizAnswer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class QuizSubmissionController extends Controller
{
    public function download(Request $request, QuizAnswer $quizAnswer): StreamedResponse
    {
        $quizAnswer->loadMissing([
            'question',
            'attempt.quiz.courseOffering.semester.schoolYear',
        ]);

        abort_unless($quizAnswer->question?->type === 'file_upload', 404);

        $user = $request->user();
        $attempt = $quizAnswer->attempt;
        $quiz = $attempt?->quiz;
        $courseOffering = $quiz?->courseOffering;

        $ownsSubmission = $attempt?->user_id === $user->id;
        $managesQuiz = $user->isSuperAdmin()
            || ($user->can('update_quiz') && $courseOffering?->user_id === $user->id);

        abort_unless($ownsSubmission || $managesQuiz, 403);

        $path = Str::replace('\\', '/', (string) $quizAnswer->answer_text);
        $expectedPrefix = "quiz-submissions/{$quiz->id}/";

        abort_unless(
            $path !== ''
            && str_starts_with($path, $expectedPrefix)
            && ! str_contains($path, '..'),
            404,
        );

        $disk = Storage::disk('local')->exists($path) ? 'local' : 'public';
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->download(
            $path,
            basename($path),
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }
}
