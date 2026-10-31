<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Http\Request;

class EvaluationSubmissionIdentity
{
    public function fingerprint(CourseOffering $courseOffering, Request $request, ?User $user = null): string
    {
        $identity = $user
            ? "user:{$user->getKey()}"
            : 'session:'.$request->session()->getId();

        return $this->hash($courseOffering, $identity);
    }

    public function forUser(CourseOffering $courseOffering, int $userId): string
    {
        return $this->hash($courseOffering, 'user:'.$userId);
    }

    /** IDs of the given classes this user already evaluated (submissions store only a per-class fingerprint). */
    public function completedClassIds(User $user, iterable $classes): array
    {
        $fingerprints = [];
        foreach ($classes as $class) {
            $fingerprints[$this->forUser($class, $user->getKey())] = $class->getKey();
        }
        if ($fingerprints === []) {
            return [];
        }
        $done = [];
        foreach (array_chunk(array_keys($fingerprints), 400) as $chunk) {
            $done = [...$done, ...FormSubmission::withoutGlobalScopes()->whereIn('submission_fingerprint', $chunk)
                ->orWhere(fn ($q) => $q->where('user_id', $user->getKey())->whereIn('course_offering_id', array_map(fn ($f) => $fingerprints[$f], $chunk)))
                ->pluck('course_offering_id')->all()];
        }

        return array_values(array_unique(array_map('intval', $done)));
    }

    private function hash(CourseOffering $courseOffering, string $identity): string
    {
        $key = (string) config('evaluations.fingerprint_key');
        throw_if($key === '', \LogicException::class, 'EVALUATION_FINGERPRINT_KEY or APP_KEY must be configured.');

        return hash_hmac(
            'sha256',
            "evaluation:{$courseOffering->getKey()}:{$identity}",
            $key,
        );
    }
}
