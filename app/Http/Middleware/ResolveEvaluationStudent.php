<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class ResolveEvaluationStudent
{
    public function handle(Request $request, Closure $next)
    {
        if (session('evaluation.expires_at', 0) > now()->timestamp) {
            $student = User::whereKey(session('evaluation.student_id'))->where('is_student', true)->first();
            if ($student) {
                // Only this evaluation request sees the selected student. The authentication
                // guard remains unchanged, so this cannot unlock assessments or staff pages.
                $request->setUserResolver(fn () => $student);
            }
        }

        return $next($request);
    }
}
