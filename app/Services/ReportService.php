<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Collection;

class ReportService
{
    /**
     * Calculate the overall score for a single submission.
     * Looks at likert_scale and rating field types only.
     */
    public function calculateSubmissionScore(FormSubmission $submission): ?float
    {
        $form = $submission->form;
        if (! $form) {
            return null;
        }

        $data = $submission->data ?? [];
        $fields = $form->fields()->whereIn('type', ['likert_scale', 'rating'])->get();

        $ratings = [];
        foreach ($fields as $field) {
            $val = $data[$field->name] ?? null;
            if ($val !== null && is_numeric($val)) {
                $ratings[] = (float) $val;
            }
        }

        if (empty($ratings)) {
            return null;
        }

        $scoringConfig = $form->scoring_config ?? [];
        $overallMethod = $scoringConfig['overall_method'] ?? 'average';

        return match ($overallMethod) {
            'sum' => array_sum($ratings),
            default => array_sum($ratings) / count($ratings),
        };
    }

    /**
     * Get the rating label for a given numeric score.
     */
    public function getRatingLabel(float $score, ?array $ratingScale = null): string
    {
        $ratingScale = $ratingScale ?? [
            ['value' => 5, 'label' => 'Outstanding'],
            ['value' => 4, 'label' => 'Very Satisfactory'],
            ['value' => 3, 'label' => 'Satisfactory'],
            ['value' => 2, 'label' => 'Fair'],
            ['value' => 1, 'label' => 'Poor'],
        ];

        $ratingScale = collect($ratingScale)->sortByDesc('value');

        foreach ($ratingScale as $scale) {
            if ($score >= ($scale['value'] - 0.5)) {
                return $scale['label'];
            }
        }

        return $ratingScale->last()['label'] ?? '—';
    }

    /**
     * Get aggregated stats for one evaluatee within a scope.
     *
     * Returns: [avg_score, rating_label, total_submissions, total_courses,
     *           completion_rate, course_offerings => [...]]
     */
    public function getEvaluateeSummary(
        User $evaluatee,
        ?int $semesterId = null,
        ?int $campusId = null,
    ): array {
        $query = CourseOffering::where('user_id', $evaluatee->id)
            ->where('is_active', true)
            ->whereNotNull('form_id');

        if ($semesterId) {
            $query->where('semester_id', $semesterId);
        }

        if ($campusId) {
            $query->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campusId));
        }

        $courseOfferings = $query
            ->with(['course', 'semester.schoolYear', 'evaluationForm'])
            ->withCount(['students', 'formSubmissions'])
            ->get();

        if ($courseOfferings->isEmpty()) {
            return [
                'avg_score' => null,
                'rating_label' => '—',
                'total_submissions' => 0,
                'total_courses' => 0,
                'completion_rate' => 0,
                'course_offerings' => collect(),
            ];
        }

        $allScores = [];
        $totalSubmissions = 0;
        $totalStudents = 0;

        foreach ($courseOfferings as $co) {
            $totalSubmissions += $co->form_submissions_count;
            $totalStudents += $co->students_count;

            $submissions = FormSubmission::where('course_offering_id', $co->id)->with('form.fields')->get();
            foreach ($submissions as $sub) {
                $score = $this->calculateSubmissionScore($sub);
                if ($score !== null) {
                    $allScores[] = $score;
                }
            }
        }

        $avgScore = ! empty($allScores) ? array_sum($allScores) / count($allScores) : null;
        $ratingLabel = $avgScore !== null
            ? $this->getRatingLabel($avgScore, $courseOfferings->first()?->evaluationForm?->rating_scale)
            : '—';
        $completionRate = $totalStudents > 0 ? round(($totalSubmissions / $totalStudents) * 100, 1) : 0;

        return [
            'avg_score' => $avgScore ? round($avgScore, 2) : null,
            'rating_label' => $ratingLabel,
            'total_submissions' => $totalSubmissions,
            'total_courses' => $courseOfferings->count(),
            'completion_rate' => $completionRate,
            'course_offerings' => $courseOfferings,
        ];
    }

    /**
     * Get per-field score breakdown for a course offering (or set of offerings).
     *
     * Returns collection of: [field_name, field_label, section, avg, min, max, count]
     */
    public function getFieldBreakdown(Collection $courseOfferingIds, ?int $formId = null): Collection
    {
        $submissionsQuery = FormSubmission::whereIn('course_offering_id', $courseOfferingIds);
        if ($formId) {
            $submissionsQuery->where('form_id', $formId);
        }
        $submissions = $submissionsQuery->with('form')->get();

        if ($submissions->isEmpty()) {
            return collect();
        }

        // Get form fields from the first submission's form
        $firstFormId = $formId ?? $submissions->first()->form_id;
        $fields = FormField::where('form_id', $firstFormId)
            ->whereIn('type', ['likert_scale', 'rating'])
            ->orderBy('order')
            ->get();

        $breakdown = [];
        foreach ($fields as $field) {
            $values = [];
            foreach ($submissions as $sub) {
                $val = ($sub->data ?? [])[$field->name] ?? null;
                if ($val !== null && is_numeric($val)) {
                    $values[] = (float) $val;
                }
            }

            if (! empty($values)) {
                $breakdown[] = [
                    'field_name' => $field->name,
                    'field_label' => $field->label,
                    'section' => $field->section ?? '—',
                    'avg' => round(array_sum($values) / count($values), 2),
                    'min' => min($values),
                    'max' => max($values),
                    'count' => count($values),
                ];
            }
        }

        return collect($breakdown);
    }

    /**
     * Get section-level averages (grouped by form field section).
     */
    public function getSectionBreakdown(Collection $courseOfferingIds, ?int $formId = null): Collection
    {
        $fieldBreakdown = $this->getFieldBreakdown($courseOfferingIds, $formId);

        return $fieldBreakdown
            ->groupBy('section')
            ->map(function ($fields, $section) {
                $avgScores = $fields->pluck('avg');

                return [
                    'section' => $section,
                    'avg' => round($avgScores->avg(), 2),
                    'min' => round($avgScores->min(), 2),
                    'max' => round($avgScores->max(), 2),
                    'field_count' => $fields->count(),
                    'fields' => $fields,
                ];
            })
            ->values();
    }

    /**
     * Get aggregated comments from submissions.
     * Returns field_comments and overall_comments arrays.
     */
    public function getAggregatedComments(Collection $courseOfferingIds): array
    {
        $submissions = FormSubmission::whereIn('course_offering_id', $courseOfferingIds)->with('form')->get();

        $fieldComments = [];
        $overallComments = [];

        foreach ($submissions as $sub) {
            $data = $sub->data ?? [];

            if (! empty($data['_field_comments'])) {
                foreach ($data['_field_comments'] as $fieldName => $comment) {
                    if (! empty(trim($comment))) {
                        $fieldComments[$fieldName][] = $comment;
                    }
                }
            }

            if (! empty($data['_overall_comment'])) {
                $overallComments[] = $data['_overall_comment'];
            }
        }

        return [
            'field_comments' => $fieldComments,
            'overall_comments' => $overallComments,
        ];
    }

    /**
     * Get department summary stats.
     */
    public function getDepartmentSummary(
        Department $department,
        ?int $semesterId = null,
    ): array {
        // Get all evaluatees in this department (instructors assigned to dept)
        // PLUS any user who has an active course offering linked to this department's courses
        $evaluatees = User::where(function ($q) use ($department) {
            // Users assigned to this department
            $q->where('instructor_department_id', $department->id);
        })
            ->orWhere(function ($q) use ($department, $semesterId) {
                // Non-instructor users who have course offerings with courses in this department
                $q->where('is_instructor', false)
                    ->whereHas('courseOfferingsAsEvaluatee', function ($coq) use ($department, $semesterId) {
                        $coq->where('is_active', true)
                            ->whereNotNull('form_id')
                            ->whereHas('semester.schoolYear', fn ($sq) => $sq->where('campus_id', $department->campus_id));
                        if ($semesterId) {
                            $coq->where('semester_id', $semesterId);
                        }
                        $coq->where(function ($inner) use ($department) {
                            $inner->whereHas('course', fn ($cq) => $cq->where('department_id', $department->id))
                                ->orWhereNull('course_id');
                        });
                    });
            })
            ->get()
            ->unique('id');  // Deduplicate in case someone matches both conditions

        $evaluateeSummaries = [];
        $allScores = [];
        $totalSubmissions = 0;
        $totalStudents = 0;

        foreach ($evaluatees as $evaluatee) {
            $summary = $this->getEvaluateeSummary($evaluatee, $semesterId, $department->campus_id);
            $evaluateeSummaries[] = [
                'evaluatee' => $evaluatee,
                'summary' => $summary,
            ];

            if ($summary['avg_score'] !== null) {
                $allScores[] = $summary['avg_score'];
            }
            $totalSubmissions += $summary['total_submissions'];
            $totalStudents += $summary['course_offerings']->sum('students_count');
        }

        // Sort by avg score descending
        usort($evaluateeSummaries, function ($a, $b) {
            return ($b['summary']['avg_score'] ?? 0) <=> ($a['summary']['avg_score'] ?? 0);
        });

        $avgScore = ! empty($allScores) ? round(array_sum($allScores) / count($allScores), 2) : null;
        $completionRate = $totalStudents > 0 ? round(($totalSubmissions / $totalStudents) * 100, 1) : 0;

        return [
            'department' => $department,
            'avg_score' => $avgScore,
            'rating_label' => $avgScore !== null ? $this->getRatingLabel($avgScore) : '—',
            'total_evaluatees' => $evaluatees->count(),
            'total_submissions' => $totalSubmissions,
            'completion_rate' => $completionRate,
            'evaluatee_rankings' => $evaluateeSummaries,
        ];
    }

    /**
     * Get score distribution across rating categories for a set of submissions.
     * Returns: ['Outstanding' => 12, 'Very Satisfactory' => 25, ...]
     */
    public function getScoreDistribution(Collection $courseOfferingIds, ?array $ratingScale = null): array
    {
        $submissions = FormSubmission::whereIn('course_offering_id', $courseOfferingIds)->with('form.fields')->get();

        $distribution = [];
        foreach ($submissions as $sub) {
            $score = $this->calculateSubmissionScore($sub);
            if ($score !== null) {
                $label = $this->getRatingLabel($score, $ratingScale);
                $distribution[$label] = ($distribution[$label] ?? 0) + 1;
            }
        }

        return $distribution;
    }
}
