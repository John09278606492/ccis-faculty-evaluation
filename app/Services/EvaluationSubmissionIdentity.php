<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\User;
use Illuminate\Http\Request;

class EvaluationSubmissionIdentity
{
    public function fingerprint(CourseOffering $courseOffering, Request $request, ?User $user = null): string
    {
        $key = (string) config('evaluations.fingerprint_key');
        throw_if($key === '', \LogicException::class, 'EVALUATION_FINGERPRINT_KEY or APP_KEY must be configured.');

        $identity = $user
            ? "user:{$user->getKey()}"
            : 'session:'.$request->session()->getId();

        return hash_hmac(
            'sha256',
            "evaluation:{$courseOffering->getKey()}:{$identity}",
            $key,
        );
    }
}
