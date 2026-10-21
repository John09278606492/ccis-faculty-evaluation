<?php

namespace App\Http\Controllers;

use App\Models\CourseOffering;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Services\EvaluationAccessService;
use App\Services\EvaluationSubmissionIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EvaluationController extends Controller
{
    public function __construct(
        private readonly EvaluationAccessService $access,
        private readonly EvaluationSubmissionIdentity $identity,
    ) {}

    public function show(Request $request, CourseOffering $courseOffering): View|RedirectResponse
    {
        $form = $this->evaluationForm($courseOffering);

        if ($form->requires_login && ! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('filament.admin.auth.login')
                ->with('warning', 'You must log in to access this evaluation form.');
        }

        $form = $this->access->authorize($courseOffering, $request->user());

        if (! $form->isAvailable()) {
            return view('evaluation.unavailable', compact('courseOffering', 'form'));
        }

        $fingerprint = $this->identity->fingerprint($courseOffering, $request, $request->user());

        return view('evaluation.show', [
            'courseOffering' => $courseOffering,
            'form' => $form,
            'fields' => $form->fields()->where('type', '!=', 'section_header')->orderBy('order')->get(),
            'alreadySubmitted' => FormSubmission::query()
                ->where('form_id', $form->id)
                ->where('course_offering_id', $courseOffering->id)
                ->where(function ($query) use ($fingerprint, $request): void {
                    $query->where('submission_fingerprint', $fingerprint);

                    if ($request->user()) {
                        $query->orWhere('user_id', $request->user()->id);
                    } else {
                        $query->orWhere(function ($legacy) use ($request): void {
                            $legacy->whereNull('user_id')
                                ->where('ip_address', $request->ip())
                                ->where('user_agent', $request->userAgent());
                        });
                    }
                })
                ->exists(),
        ]);
    }

    public function store(Request $request, CourseOffering $courseOffering): RedirectResponse
    {
        $form = $this->evaluationForm($courseOffering);

        if ($form->requires_login && ! $request->user()) {
            return redirect()->route('filament.admin.auth.login')
                ->with('warning', 'You must log in to submit this evaluation.');
        }

        $form = $this->access->authorize($courseOffering, $request->user());
        $formFields = $form->fields()->where('type', '!=', 'section_header')->orderBy('order')->get();
        $validated = $request->validate($this->validationRules($form, $formFields), [
            'field_comments.*.required' => 'A comment is required for each question.',
            'overall_comment.required' => 'An overall comment is required.',
        ]);

        $fingerprint = $this->identity->fingerprint($courseOffering, $request, $request->user());

        $legacySubmissionExists = FormSubmission::query()
            ->where('form_id', $form->id)
            ->where('course_offering_id', $courseOffering->id)
            ->when(
                $request->user(),
                fn ($query) => $query->where('user_id', $request->user()->id),
                fn ($query) => $query->whereNull('user_id')
                    ->where('ip_address', $request->ip())
                    ->where('user_agent', $request->userAgent()),
            )
            ->exists();

        if ($legacySubmissionExists) {
            return back()->withInput()->with('error', 'You have already submitted this evaluation.');
        }

        $fieldData = $validated['fields'] ?? [];
        $fieldComments = array_filter(
            $validated['field_comments'] ?? [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        );

        if ($fieldComments !== []) {
            $fieldData['_field_comments'] = $fieldComments;
        }

        if (! empty($validated['overall_comment'])) {
            $fieldData['_overall_comment'] = $validated['overall_comment'];
        }

        try {
            DB::transaction(function () use ($form, $courseOffering, $fingerprint, $fieldData): void {
                $lockedForm = Form::query()->lockForUpdate()->findOrFail($form->id);

                if (! $lockedForm->isAvailable()) {
                    throw new AuthorizationException('This evaluation form is no longer available.');
                }

                FormSubmission::create([
                    'form_id' => $lockedForm->id,
                    'course_offering_id' => $courseOffering->id,
                    'submission_fingerprint' => $fingerprint,
                    'user_id' => null,
                    'data' => $fieldData,
                    'ip_address' => null,
                    'user_agent' => null,
                    'submitted_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return back()->withInput()->with('error', 'You have already submitted this evaluation.');
        }

        return redirect()->route('evaluate.success', $courseOffering);
    }

    public function success(Request $request, CourseOffering $courseOffering): View|RedirectResponse
    {
        $form = $this->evaluationForm($courseOffering);

        if ($form->requires_login && ! $request->user()) {
            return redirect()->route('filament.admin.auth.login');
        }

        $this->access->authorize($courseOffering, $request->user());

        return view('evaluation.success', compact('courseOffering'));
    }

    public function preview(Request $request, Form $form): View
    {
        Gate::forUser($request->user())->authorize('view', $form);

        if (! $request->user()->isSuperAdmin()
            && ! $form->campuses()->whereIn('campuses.id', $request->user()->campuses()->select('campuses.id'))->exists()) {
            abort(403, 'You do not have access to a campus assigned to this form.');
        }

        return view('evaluation.preview', [
            'form' => $form,
            'fields' => $form->fields()->where('type', '!=', 'section_header')->orderBy('order')->get(),
        ]);
    }

    private function evaluationForm(CourseOffering $courseOffering): Form
    {
        $courseOffering->loadMissing('evaluationForm');

        abort_unless($courseOffering->evaluationForm, 404, 'No evaluation form is assigned.');

        return $courseOffering->evaluationForm;
    }

    private function validationRules(Form $form, $fields): array
    {
        $fieldNames = $fields->pluck('name')->all();
        $allowedKeys = $fieldNames === [] ? 'array' : 'array:'.implode(',', $fieldNames);
        $rules = [
            'fields' => ['sometimes', $allowedKeys],
            'field_comments' => ['sometimes', $allowedKeys],
            'overall_comment' => ['nullable', 'string', 'max:5000'],
        ];

        $scoringConfig = $form->scoring_config ?? [];
        $commentsRequired = ($scoringConfig['field_comments'] ?? 'optional') === 'required';

        foreach ($fields as $field) {
            $rules["fields.{$field->name}"] = $this->fieldRules($field, $form->rating_scale ?? []);
            if (in_array($field->type, ['multiselect', 'checkboxlist'], true)) {
                $options = collect($field->options ?? [])->map(
                    static fn (mixed $option): mixed => is_array($option) ? ($option['value'] ?? null) : $option,
                )->filter(static fn (mixed $value): bool => $value !== null)->values()->all();
                $rules["fields.{$field->name}.*"] = [Rule::in($options)];
            }
            $rules["field_comments.{$field->name}"] = [
                $commentsRequired ? 'required' : 'nullable',
                'string',
                'max:2000',
            ];
        }

        if (($scoringConfig['overall_comment'] ?? 'optional') === 'required') {
            $rules['overall_comment'][0] = 'required';
        }

        return $rules;
    }

    private function fieldRules(FormField $field, array $ratingScale = []): array
    {
        $presence = $field->is_required ? 'required' : 'nullable';
        $options = collect($field->options ?? [])->map(
            static fn (mixed $option): mixed => is_array($option) ? ($option['value'] ?? null) : $option,
        )->filter(static fn (mixed $value): bool => $value !== null)->values()->all();
        // The scale belongs to the form, which is already loaded: reading it from
        // the field's relation would lazy-load it once per field.
        $scale = collect($ratingScale)->pluck('value')->all();

        $rules = match ($field->type) {
            'text', 'textarea', 'tel' => [$presence, 'string', 'max:5000'],
            'email' => [$presence, 'email:rfc', 'max:255'],
            'url' => [$presence, 'url:http,https', 'max:2048'],
            'number' => [$presence, 'numeric'],
            'date' => [$presence, 'date_format:Y-m-d'],
            'datetime' => [$presence, 'date'],
            'time' => [$presence, 'date_format:H:i'],
            'select', 'radio' => [$presence, Rule::in($options)],
            'multiselect', 'checkboxlist' => [$presence, 'array', 'max:100'],
            'checkbox' => [$presence, 'boolean'],
            'rating', 'likert_scale' => [$presence, Rule::in($scale !== [] ? $scale : [1, 2, 3, 4, 5])],
            'file' => ['prohibited'],
            default => ['prohibited'],
        };

        return array_merge($rules, array_values(array_filter(
            $field->validation_rules ?? [],
            static fn (mixed $rule): bool => is_string($rule) && ! str_starts_with($rule, 'required'),
        )));
    }
}
