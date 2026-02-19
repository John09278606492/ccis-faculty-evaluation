<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EvaluationController extends Controller
{
    /**
     * Show the evaluation form for a course offering.
     */
    public function show(CourseOffering $courseOffering)
    {
        // Check if form is assigned
        if (!$courseOffering->form_id) {
            abort(404, 'No evaluation form assigned to this course offering.');
        }

        $form = $courseOffering->evaluationForm;

        // Check if form is active
        if (!$form->isAvailable()) {
            return view('evaluation.unavailable', [
                'courseOffering' => $courseOffering,
                'form' => $form,
            ]);
        }

        // Check if user's role is allowed to access this form
        if (Auth::check() && !empty($form->evaluator_roles)) {
            $user = Auth::user();
            $userRoles = $user->getRoleNames()->toArray();
            $allowedRoles = $form->evaluator_roles;

            // Check if user has any of the allowed roles
            if (empty(array_intersect($userRoles, $allowedRoles))) {
                return view('evaluation.unauthorized', [
                    'courseOffering' => $courseOffering,
                    'form' => $form,
                    'message' => 'This evaluation form is only accessible to: ' . implode(', ', array_map(fn($r) => ucwords(str_replace('_', ' ', $r)), $allowedRoles)),
                ]);
            }
        }

        // Check if user already submitted (if logged in)
        $alreadySubmitted = false;
        if (Auth::check()) {
            $alreadySubmitted = FormSubmission::where('form_id', $form->id)
                ->where('course_offering_id', $courseOffering->id)
                ->where('user_id', Auth::id())
                ->exists();
        }

        return view('evaluation.show', [
            'courseOffering' => $courseOffering,
            'form' => $form,
            'fields' => $form->fields()->orderBy('order')->get(),
            'alreadySubmitted' => $alreadySubmitted,
        ]);
    }

    /**
     * Store the evaluation submission.
     */
    public function store(Request $request, CourseOffering $courseOffering)
    {
        // Check if form is assigned
        if (!$courseOffering->form_id) {
            abort(404, 'No evaluation form assigned.');
        }

        $form = $courseOffering->evaluationForm;

        // Validate required fields
        $rules = [];
        foreach ($form->fields as $field) {
            if ($field->is_required) {
                $rules["fields.{$field->name}"] = 'required';
            }
        }

        // Validate comment fields based on form config
        $scoringConfig = $form->scoring_config ?? [];
        $fieldCommentsMode = $scoringConfig['field_comments'] ?? 'optional';
        $overallCommentMode = $scoringConfig['overall_comment'] ?? 'optional';

        if ($fieldCommentsMode === 'required') {
            foreach ($form->fields as $field) {
                $rules["field_comments.{$field->name}"] = 'required|string|min:1';
            }
        }

        if ($overallCommentMode === 'required') {
            $rules['overall_comment'] = 'required|string|min:1';
        }

        $request->validate($rules, [
            'field_comments.*.required' => 'A comment is required for each question.',
            'overall_comment.required' => 'An overall comment is required.',
        ]);

        // Check if already submitted (if logged in)
        if (Auth::check()) {
            $existing = FormSubmission::where('form_id', $form->id)
                ->where('course_offering_id', $courseOffering->id)
                ->where('user_id', Auth::id())
                ->first();

            if ($existing) {
                return redirect()->back()->with('error', 'You have already submitted this evaluation.');
            }
        }

        // Create submission
        $fieldData = $request->input('fields', []);

        // Add per-field comments (independent of form fields)
        $fieldComments = array_filter($request->input('field_comments', []));
        if (!empty($fieldComments)) {
            $fieldData['_field_comments'] = $fieldComments;
        }

        // Add overall comment (independent of form fields)
        $overallComment = $request->input('overall_comment');
        if (!empty($overallComment)) {
            $fieldData['_overall_comment'] = $overallComment;
        }

        FormSubmission::create([
            'form_id' => $form->id,
            'course_offering_id' => $courseOffering->id,
            'user_id' => Auth::id(),
            'data' => $fieldData,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'submitted_at' => now(),
        ]);

        return redirect()->route('evaluate.success', $courseOffering);
    }

    /**
     * Show success page after submission.
     */
    public function success(CourseOffering $courseOffering)
    {
        return view('evaluation.success', [
            'courseOffering' => $courseOffering,
        ]);
    }

    /**
     * Preview a form (for admin use before assigning).
     */
    public function preview(\App\Models\Form $form)
    {
        $fields = $form->fields()->orderBy('order')->get();

        return view('evaluation.preview', [
            'form' => $form,
            'fields' => $fields,
        ]);
    }
}
