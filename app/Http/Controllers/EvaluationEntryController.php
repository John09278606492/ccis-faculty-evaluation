<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EvaluationAccessService;
use App\Services\EvaluationSubmissionIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class EvaluationEntryController extends Controller
{
    public function show(Request $request, EvaluationAccessService $access, EvaluationSubmissionIdentity $identity)
    {
        $student = session('evaluation.expires_at', 0) > now()->timestamp
            ? User::whereKey(session('evaluation.student_id'))->where('is_student', true)->first() : null;
        $assignments = collect();
        if ($student) {
            $assignments = $student->courseOfferings()->where('is_active', true)->whereNotNull('form_id')
                ->with(['course', 'evaluatee', 'semester.schoolYear', 'evaluationCycle', 'evaluationForm.campuses', 'evaluationForm.departments'])->get()
                ->filter(function ($offering) use ($student, $access) {
                    try {
                        $access->authorize($offering, $student);

                        return true;
                    } catch (AuthorizationException) {
                        return false;
                    }
                })->map(function ($offering) use ($student, $identity, $request) {
                    $fingerprint = $identity->fingerprint($offering, $request, $student);
                    $completed = $offering->formSubmissions()->where('form_id', $offering->form_id)
                        ->where(fn ($query) => $query->where('submission_fingerprint', $fingerprint)->orWhere('user_id', $student->id))->exists();

                    return ['offering' => $offering, 'completed' => $completed, 'available' => $offering->isEvaluationAvailable()];
                })
                // Instructors still to evaluate first, then ones not open yet, completed last.
                ->sortBy(fn ($a) => ($a['completed'] ? 2 : ($a['available'] ? 0 : 1)).'|'.mb_strtoupper((string) $a['offering']->evaluatee?->name))->values();
        }

        return view('evaluation.entry', compact('student', 'assignments'));
    }

    public function identify(Request $request)
    {
        $data = $request->validate(['student_id' => ['required', 'string', 'max:100']]);
        $student = User::where('student_id', trim($data['student_id']))->where('is_student', true)->first();
        if (! $student) {
            return back()->withErrors(['student_id' => 'Student ID not found. Please check your ID or contact your school.'])->withInput();
        }
        session()->regenerate();
        session()->put(['evaluation.student_id' => $student->id, 'evaluation.expires_at' => now()->addMinutes(30)->timestamp]);
        $offering = session()->pull('evaluation.intended_offering');

        return $offering ? redirect()->route('evaluate.course-offering', $offering) : redirect()->route('evaluation.entry');
    }

    public function leave()
    {
        session()->forget('evaluation');

        return redirect()->route('evaluation.entry');
    }
}
