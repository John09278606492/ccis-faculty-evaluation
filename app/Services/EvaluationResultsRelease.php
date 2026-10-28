<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * An instructor sees the scores they received only after the evaluation has closed and
 * enough students have responded, so no single response can be traced back to a student.
 * Admins reviewing other people's results are not limited.
 */
class EvaluationResultsRelease
{
    public static function minimumResponses(): int
    {
        return max(1, (int) config('evaluations.min_responses_for_results', 5));
    }

    public static function isClosed(CourseOffering $offering): bool
    {
        $offering->loadMissing(['evaluationForm', 'semester']);
        $form = $offering->evaluationForm;
        $offering->loadMissing('evaluationCycle');
        if ($offering->evaluationCycle) {
            return in_array($offering->evaluationCycle->status, ['closed', 'archived'], true)
                || (bool) $offering->evaluationCycle->ends_at?->isPast();
        }

        return ! $offering->is_active
            || ! $form
            || ! $form->is_active
            || ($form->ends_at && $form->ends_at->isPast())
            || ($offering->semester?->end_date && $offering->semester->end_date->endOfDay()->isPast());
    }

    public static function completeResponses(CourseOffering $offering): int
    {
        $offering->loadMissing('evaluationForm.fields');
        $submissions = $offering->relationLoaded('formSubmissions') ? $offering->formSubmissions : $offering->formSubmissions()->where('form_id', $offering->form_id)->get();

        return app(EvaluationScoring::class)->assignment($offering, $submissions)['count'];
    }

    public static function minimumFor(CourseOffering $offering): int
    {
        $offering->loadMissing('evaluationForm');

        return $offering->evaluationForm?->isSupervisorInstrument() ? 1 : self::minimumResponses();
    }

    public static function isReleased(CourseOffering $offering, ?int $responses = null): bool
    {
        // Callers' historical SQL counts may include old instruments or incomplete answers.
        return self::isClosed($offering) && self::completeResponses($offering) >= self::minimumFor($offering);
    }

    public static function canSeeScores(?User $viewer, CourseOffering $offering): bool
    {
        if (! $viewer || $viewer->isStudent()) {
            return false;
        }
        $offering->loadMissing('semester.schoolYear.campus');
        $campus = $offering->campus;
        if (! $campus || ! $viewer->canAccessTenant($campus)) {
            return false;
        }
        if ($viewer->id === $offering->user_id) {
            return self::isReleased($offering);
        }

        return $viewer->can('page_EvaluationResults')
            && app(AssignedDataAccess::class)->managesOffering($viewer, $offering);
    }

    public static function ownOfferings(User $user): Collection
    {
        return CourseOffering::where('user_id', $user->id)
            ->when(filament()->getTenant(), fn ($q, $campus) => $q->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id)))
            ->whereHas('formSubmissions', fn ($q) => $q->whereColumn('form_submissions.form_id', 'course_offerings.form_id'))
            ->with(['course', 'evaluationForm.fields', 'semester', 'formSubmissions' => fn ($q) => $q->whereHas('courseOffering', fn ($q) => $q->whereColumn('course_offerings.form_id', 'form_submissions.form_id'))])
            ->get()->each(fn ($offering) => $offering->setAttribute('form_submissions_count', self::completeResponses($offering)));
    }

    /**
     * Why an instructor cannot see an offering's results yet, or null when they can.
     */
    public static function pendingReason(CourseOffering $offering): ?string
    {
        $responses = self::completeResponses($offering);
        $minimum = self::minimumFor($offering);

        if (! self::isClosed($offering)) {
            return "{$responses} ".str('response')->plural($responses).' so far. Results open after the evaluation closes'
                .($responses < $minimum ? " and at least {$minimum} students have responded." : '.');
        }

        if ($responses < $minimum) {
            return "{$responses} ".str('response')->plural($responses)." received. Results are shown only when at least {$minimum} students respond, to keep answers anonymous.";
        }

        return null;
    }
}
