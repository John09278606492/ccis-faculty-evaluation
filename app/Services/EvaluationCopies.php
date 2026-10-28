<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class EvaluationCopies
{
    public function query(Campus $campus, ?User $actor = null)
    {
        $query = FormSubmission::query()->whereHas('form', fn ($q) => $q->whereIn('settings->annex', ['A', 'B']))
            ->whereHas('courseOffering.semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id));

        return ($actor ?? auth()->user()) ? app(AssignedDataAccess::class)->apply($query, $actor ?? auth()->user(), $campus) : $query->whereRaw('1 = 0');
    }

    public function allowed(User $user, Campus $campus): bool
    {
        return ! $user->isStudent() && $user->canAccessTenant($campus) && ($user->can('page_EvaluationResults') || $user->can('view_form'));
    }

    public function previewUrl(User $user, Campus $campus, array $ids): string
    {
        abort_unless($this->allowed($user, $campus), 403);
        $ids = array_values(array_unique($ids));
        abort_unless(count($ids) > 0 && $this->query($campus, $user)->whereKey($ids)->count() === count($ids), 403);
        $token = (string) Str::uuid();
        Cache::put('evaluation-copies:'.$token, ['user' => $user->id, 'campus' => $campus->id, 'ids' => $ids], now()->addMinutes(30));

        return URL::signedRoute('evaluation.copies', ['token' => $token]);
    }

    public function identity(FormSubmission $submission): ?array
    {
        if ($submission->evaluator_identity) {
            return $submission->evaluator_identity;
        }
        if ($submission->user_id && ($user = User::withTrashed()->find($submission->user_id))) {
            return ['name' => $user->name, 'student_id' => $user->student_id];
        }
        $campusId = $submission->courseOffering->semester->schoolYear->campus_id;
        foreach (User::withTrashed()->whereHas('campuses', fn ($q) => $q->where('campuses.id', $campusId))->cursor() as $user) {
            $hash = hash_hmac('sha256', "evaluation:{$submission->course_offering_id}:user:{$user->id}", config('evaluations.fingerprint_key'));
            if ($submission->submission_fingerprint && hash_equals($submission->submission_fingerprint, $hash)) {
                return ['name' => $user->name, 'student_id' => $user->student_id];
            }
        }

        return null;
    }
}
