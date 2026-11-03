<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\Quiz;
use App\Models\User;
use App\Services\StudentSignIns;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentSignInController extends Controller
{
    public function check()
    {
        if (Auth::guard(Filament::getPanel('admin')->getAuthGuard())->check()) {
            return response()->json(['state' => 'expired']);
        }
        $token = session('student_sign_in_token');
        if (! is_string($token)) {
            return response()->json(['state' => 'expired']);
        }

        return DB::transaction(function () use ($token) {
            $request = DB::table('student_sign_in_requests')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $request || $request->consumed_at || now()->gte($request->expires_at)) {
                return response()->json(['state' => 'expired']);
            }
            if (! $request->approved_at) {
                return response()->json(['state' => 'pending']);
            }
            $student = User::find($request->user_id);
            if (! $student?->is_student || ! $student->canAccessPanel(Filament::getPanel('admin'))) {
                return response()->json(['state' => 'expired']);
            }
            $actor = User::find($request->approved_by);
            $quiz = $request->quiz_id ? Quiz::find($request->quiz_id) : null;
            $campus = ! $quiz && preg_match('~^/(\d+)$~', $request->destination ?? '', $match) ? Campus::find($match[1]) : null;
            if (! $actor || ($request->quiz_id && (! $quiz || ! $actor->can('update', $quiz) || ! $quiz->isAssignedTo($student->id)))
                || ! StudentSignIns::canApprove($actor, $student, $quiz?->courseOffering, $campus)) {
                return response()->json(['state' => 'expired']);
            }
            DB::table('student_sign_in_requests')->where('id', $request->id)->update(['consumed_at' => now()]);
            Auth::guard(Filament::getPanel('admin')->getAuthGuard())->login($student);
            session()->forget(['student_sign_in_token', 'student_sign_in_code']);
            session()->regenerate();

            return response()->json(['state' => 'approved', 'url' => $request->destination ?: '/']);
        });
    }
}
