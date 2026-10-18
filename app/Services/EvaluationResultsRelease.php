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

        return ! $offering->is_active
            || ! $form
            || ! $form->is_active
            || ($form->ends_at && $form->ends_at->isPast())
            || ($offering->semester?->end_date && $offering->semester->end_date->endOfDay()->isPast());
    }

    public static function isReleased(CourseOffering $offering, ?int $responses = null): bool
    {
        $responses ??= $offering->form_submissions_count ?? $offering->formSubmissions()->count();

        return self::isClosed($offering) && $responses >= self::minimumResponses();
    }

    /**
     * Only the person being evaluated waits for the release.
     */
    public static function canSeeScores(?User $viewer, CourseOffering $offering): bool
    {
        return $viewer && ($viewer->id !== $offering->user_id || self::isReleased($offering));
    }

    /**
     * @return Collection<int, CourseOffering> the viewer's own course offerings that have responses, with
     *                                         form_submissions_count loaded
     */
    public static function ownOfferings(User $user): Collection
    {
        return CourseOffering::query()
            ->where('user_id', $user->id)
            ->whereHas('formSubmissions')
            ->with(['course', 'evaluationForm', 'semester'])
            ->withCount('formSubmissions')
            ->get();
    }

    /**
     * Why an instructor cannot see an offering's results yet, or null when they can.
     */
    public static function pendingReason(CourseOffering $offering): ?string
    {
        $responses = $offering->form_submissions_count ?? $offering->formSubmissions()->count();
        $minimum = self::minimumResponses();

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
