<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportService
{
    public function calculateSubmissionScore(FormSubmission $submission): ?float
    {
        $submission->loadMissing('form.fields');

        return $submission->form ? app(EvaluationScoring::class)->response($submission->form, $submission)['mean_5'] ?? null : null;
    }

    public function getRatingLabel(float $score, ?array $ratingScale = null): string
    {
        $scale = collect($ratingScale ?: [['value' => 5, 'label' => 'Outstanding'], ['value' => 4, 'label' => 'Very Satisfactory'],
            ['value' => 3, 'label' => 'Satisfactory'], ['value' => 2, 'label' => 'Fair'], ['value' => 1, 'label' => 'Poor']])->sortByDesc('value');
        foreach ($scale as $item) {
            if ($score >= $item['value'] - 0.5) {
                return $item['label'];
            }
        }

        return $scale->last()['label'] ?? '—';
    }

    public function offerings(array $context = []): Builder
    {
        $query = CourseOffering::query()->where(fn ($q) => $q->where('is_active', true)->orWhereHas('formSubmissions'))
            ->whereNotNull('form_id')
            ->when($context['campus_id'] ?? null, fn ($q, $id) => $q->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $id)))
            ->when($context['semester_id'] ?? null, fn ($q, $id) => $q->where('semester_id', $id))
            ->when($context['form_id'] ?? null, fn ($q, $id) => $q->where('form_id', $id))
            ->when($context['department_id'] ?? null, fn ($q, $id) => $q->where(fn ($q) => $q->where('teaching_department_id', $id)
                ->orWhere(fn ($q) => $q->whereNull('teaching_department_id')->where(fn ($q) => $q->whereHas('course', fn ($q) => $q->where('department_id', $id))
                    ->orWhere(fn ($q) => $q->whereNull('course_id')->whereHas('evaluatee', fn ($q) => $q->where('instructor_department_id', $id)))))))
            ->when($context['evaluation_cycle_id'] ?? null, fn ($q, $id) => $q->where('evaluation_cycle_id', $id));
        if (auth()->user() && ! empty($context['campus_id'])) {
            $campus = Campus::find($context['campus_id']);
            if (! $campus) {
                return $query->whereRaw('1 = 0');
            }
            app(AssignedDataAccess::class)->apply($query, auth()->user(), $campus);
        }
        if (! empty($context['evaluation_type'])) {
            $ids = (clone $query)->with('evaluationForm')->get()->filter(fn ($co) => app(EvaluationScoring::class)->instrument($co) === $context['evaluation_type'])->pluck('id');
            $query->whereIn('course_offerings.id', $ids);
        }

        return $query;

    }

    /** Annex C/D pair SET and SEF from the same evaluation; detail reports keep exact filters. */
    public function facultyReportOfferings(int $facultyId, array $context = []): Builder
    {
        $formId = $context['form_id'] ?? null;
        $query = $this->offerings([...$context, 'form_id' => null])->where('user_id', $facultyId);
        if (! $formId) {
            return $query;
        }
        $assignments = (clone $query)->with(['evaluationForm', 'course', 'evaluatee'])->get();
        $group = fn ($class) => $class->semester_id.':'.($class->evaluation_cycle_id ?? 'legacy').':'
            .($class->teaching_department_id ?? $class->course?->department_id ?? $class->evaluatee?->instructor_department_id);
        $pairs = [];
        foreach ($assignments->where('form_id', $formId) as $class) {
            $type = app(EvaluationScoring::class)->instrument($class);
            if (in_array($type, ['SET', 'SEF'], true)) {
                $pairs[$group($class)][] = $type === 'SET' ? 'SEF' : 'SET';
            }
        }
        $ids = $assignments->filter(fn ($class) => (int) $class->form_id === (int) $formId
            || in_array(app(EvaluationScoring::class)->instrument($class), $pairs[$group($class)] ?? [], true))->pluck('id');
        return $query->whereKey($ids);
    }

    public function getEvaluateeSummary(User $evaluatee, ?int $semesterId = null, ?int $campusId = null, array $context = []): array
    {
        $context = [...$context, 'semester_id' => $semesterId ?? ($context['semester_id'] ?? null), 'campus_id' => $campusId ?? ($context['campus_id'] ?? null)];
        $offerings = $this->offerings($context)->where('user_id', $evaluatee->id)
            ->with(['teachingDepartment', 'course.department', 'semester.schoolYear', 'evaluationForm.fields', 'formSubmissions', 'students' => fn ($q) => $q->withTrashed()])
            ->withCount(['students' => fn ($q) => $q->withTrashed(), 'formSubmissions' => fn ($q) => $q->whereColumn('form_submissions.form_id', 'course_offerings.form_id')])->get();
        if (auth()->id() === $evaluatee->id) {
            $offerings = $offerings->filter(fn ($co) => EvaluationResultsRelease::isReleased($co));
        }
        if (! empty($context['evaluation_type'])) {
            $offerings = $offerings->filter(fn ($co) => app(EvaluationScoring::class)->instrument($co) === $context['evaluation_type']);
        }
        $rows = collect();
        $completed = $enrolled = 0;
        foreach ($offerings as $co) {
            $submissions = $co->formSubmissions->where('form_id', $co->form_id);
            $rows->push(app(EvaluationScoring::class)->assignment($co, $submissions));
            if (app(EvaluationScoring::class)->instrument($co) !== 'SET') {
                continue;
            }
            $enrolled += $co->students->count();
            $fingerprints = $submissions->pluck('submission_fingerprint')->filter()->flip();
            $legacy = $submissions->pluck('user_id')->filter()->flip();
            foreach ($co->students as $student) {
                if ($fingerprints->has(app(EvaluationSubmissionIdentity::class)->fingerprint($co, request(), $student)) || $legacy->has($student->id)) {
                    $completed++;
                }
            }
        }
        $instruments = app(EvaluationScoring::class)->instruments($rows);
        $ratedTypes = collect($instruments)->filter(fn ($row) => $row['count'] > 0);
        $mean = $ratedTypes->count() === 1 ? $ratedTypes->first()['mean_5'] : null;

        return ['avg_score' => $mean !== null ? round($mean, 2) : null, 'rating_label' => $mean !== null ? $this->getRatingLabel($mean) : ($ratedTypes->count() > 1 ? 'Separate instrument ratings' : '—'),
            'total_submissions' => $rows->sum('submission_count'), 'complete_responses' => $rows->sum('count'), 'incomplete_responses' => $rows->sum('incomplete_count'),
            'total_courses' => $offerings->count(), 'completion_rate' => $enrolled ? round($completed / $enrolled * 100, 1) : 0,
            'completed_students' => $completed, 'total_students' => $enrolled, 'course_offerings' => $offerings, 'instruments' => $instruments, 'assignment_scores' => $rows];
    }

    public function assignmentCompletion(CourseOffering $assignment): array
    {
        $assignment->loadMissing('historicalStudents', 'evaluationForm');
        if (app(EvaluationScoring::class)->instrument($assignment) !== 'SET') {
            return ['expected' => 0, 'completed' => 0, 'percentage' => null];
        }
        $submissions = $assignment->formSubmissions()->where('form_id', $assignment->form_id)->get(['id', 'submission_fingerprint', 'user_id']);
        $fingerprints = $submissions->pluck('submission_fingerprint')->filter()->flip();
        $legacy = $submissions->pluck('user_id')->filter()->flip();
        $completed = $assignment->historicalStudents->filter(fn ($student) => $legacy->has($student->id)
            || $fingerprints->has(app(EvaluationSubmissionIdentity::class)->fingerprint($assignment, request(), $student)))->count();
        $expected = $assignment->historicalStudents->count();

        return ['expected' => $expected, 'completed' => $completed, 'percentage' => $expected ? round($completed / $expected * 100, 1) : null];
    }

    private function currentSubmissions(Collection $ids, ?int $formId = null): Collection
    {
        return FormSubmission::whereIn('course_offering_id', $ids)->whereHas('courseOffering', fn ($q) => $q->whereColumn('course_offerings.form_id', 'form_submissions.form_id'))
            ->when($formId, fn ($q) => $q->where('form_id', $formId))->with('form.fields')->get();
    }

    public function getFieldBreakdown(Collection $courseOfferingIds, ?int $formId = null): Collection
    {
        $rows = [];
        foreach ($this->currentSubmissions($courseOfferingIds, $formId)->groupBy('form_id') as $submissions) {
            $form = $submissions->first()->form;
            $scored = $submissions->map(fn ($s) => app(EvaluationScoring::class)->response($form, $s))->filter();
            foreach ($form->fields->whereIn('type', ['rating', 'likert_scale']) as $field) {
                $values = $scored->map(fn ($row) => $row['values'][$field->name]);
                if ($values->isEmpty()) {
                    continue;
                }
                $rows[] = ['form_id' => $form->id, 'form_name' => $form->name, 'field_name' => $field->name, 'field_label' => $field->question_text,
                    'section' => $field->section ?: 'General', 'avg' => round($values->avg(), 2), 'min' => $values->min(), 'max' => $values->max(), 'count' => $values->count()];
            }
        }

        return collect($rows);
    }

    public function getSectionBreakdown(Collection $courseOfferingIds, ?int $formId = null): Collection
    {
        return $this->getFieldBreakdown($courseOfferingIds, $formId)->groupBy(fn ($r) => $r['form_id'].':'.$r['section'])
            ->map(fn ($fields) => ['section' => $fields->first()['section'], 'form_name' => $fields->first()['form_name'], 'avg' => round($fields->avg('avg'), 2),
                'min' => $fields->min('min'), 'max' => $fields->max('max'), 'field_count' => $fields->count(), 'fields' => $fields])->values();
    }

    public function getAggregatedComments(Collection $courseOfferingIds): array
    {
        $fieldComments = [];
        $overallComments = [];
        foreach ($this->currentSubmissions($courseOfferingIds) as $sub) {
            foreach ($sub->data['_field_comments'] ?? [] as $name => $comment) {
                if (is_string($comment) && trim($comment) !== '') {
                    $fieldComments[$name][] = $comment;
                }
            }
            if (is_string($sub->data['_overall_comment'] ?? null) && trim($sub->data['_overall_comment']) !== '') {
                $overallComments[] = $sub->data['_overall_comment'];
            }
        }

        return ['field_comments' => $fieldComments, 'overall_comments' => $overallComments];
    }

    public function getDepartmentSummary(Department $department, ?int $semesterId = null): array
    {
        $context = ['campus_id' => $department->campus_id, 'semester_id' => $semesterId, 'department_id' => $department->id];
        $evaluatees = User::whereIn('id', $this->offerings($context)->select('user_id'))->get();
        $summaries = [];
        $instrumentRows = collect();
        $submissions = $completed = $enrolled = 0;
        foreach ($evaluatees as $evaluatee) {
            $summary = $this->getEvaluateeSummary($evaluatee, $semesterId, $department->campus_id, $context);
            $summaries[] = ['evaluatee' => $evaluatee, 'summary' => $summary];
            $instrumentRows = $instrumentRows->merge($summary['assignment_scores']);
            $submissions += $summary['total_submissions'];
            $completed += $summary['completed_students'];
            $enrolled += $summary['total_students'];
        }
        $instruments = app(EvaluationScoring::class)->instruments($instrumentRows);
        $rated = collect($instruments)->filter(fn ($r) => $r['count'] > 0);
        $mean = $rated->count() === 1 ? $rated->first()['mean_5'] : null;
        usort($summaries, fn ($a, $b) => ($b['summary']['avg_score'] ?? 0) <=> ($a['summary']['avg_score'] ?? 0));

        return ['department' => $department, 'avg_score' => $mean !== null ? round($mean, 2) : null,
            'rating_label' => $mean !== null ? $this->getRatingLabel($mean) : ($rated->count() > 1 ? 'Separate instrument ratings' : '—'),
            'total_evaluatees' => $evaluatees->count(), 'total_submissions' => $submissions, 'completion_rate' => $enrolled ? round($completed / $enrolled * 100, 1) : 0,
            'evaluatee_rankings' => $summaries, 'instruments' => $instruments];
    }

    public function getScoreDistribution(Collection $courseOfferingIds, ?array $ratingScale = null): array
    {
        $distribution = [];
        foreach ($this->currentSubmissions($courseOfferingIds) as $sub) {
            $score = $this->calculateSubmissionScore($sub);
            if ($score !== null) {
                $label = $this->getRatingLabel($score);
                $distribution[$label] = ($distribution[$label] ?? 0) + 1;
            }
        }

        return $distribution;
    }
}
