<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentSignIns
{
    public static function request(User $student): void
    {
        self::cancel();
        DB::table('student_sign_in_requests')->where('expires_at', '<', now())->delete();
        $token = bin2hex(random_bytes(32));
        $code = 'B-'.strtoupper(Str::random(6));
        DB::table('student_sign_in_requests')->insert([
            'user_id' => $student->id, 'token_hash' => hash('sha256', $token), 'verification_code' => $code,
            'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now(),
        ]);
        session()->put('student_sign_in_token', $token);
        session()->put('student_sign_in_code', $code);
    }

    public static function cancel(): void
    {
        session()->forget('student_sign_in_code');
        if ($token = session()->pull('student_sign_in_token')) {
            DB::table('student_sign_in_requests')->where('token_hash', hash('sha256', $token))->delete();
        }
    }

    public static function canApprove(User $actor, User $student, ?CourseOffering $offering = null, ?Campus $campus = null): bool
    {
        if ($actor->is_student || ! $student->is_student) {
            return false;
        }
        if ($offering) {
            $campus = $offering->campus;

            return $campus && $actor->canAccessTenant($campus)
                && app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $campus)->whereKey($offering->id)->exists()
                && $actor->can('update', $offering)
                && ($offering->user_id === $actor->id || $actor->can('viewAny', User::class))
                && $offering->students()->whereKey($student->id)->exists();
        }
        if ($actor->hasRole('super_admin')) {
            return true;
        }
        $campus ??= Filament::getTenant();

        return $campus && $actor->can('update', $student)
            && $actor->campuses()->whereKey($campus->id)->exists()
            && $student->campuses()->whereKey($campus->id)->exists()
            && app(AssignedDataAccess::class)->apply(User::query(), $actor, $campus)->whereKey($student->id)->exists();
    }

    public static function approve(Collection $students, ?CourseOffering $offering = null, ?Quiz $assessment = null, ?array $requestIds = null): int
    {
        $actor = auth()->user();
        if ($assessment) {
            abort_unless($actor && ! $actor->is_student && $actor->can('update', $assessment), 403);
            $offering = $assessment->courseOffering;
        }
        // Authorize the complete selection before modifying any requests.
        foreach ($students as $student) {
            abort_unless($actor && self::canApprove($actor, $student, $offering), 403);
            abort_if($assessment && ! $assessment->isAssignedTo($student->id), 403, 'Assign the student to this assessment before approving sign-in.');
        }

        $destination = $assessment ? '/'.$assessment->campus->id.'/take-assessment/'.$assessment->id
            : ($offering?->form_id ? '/evaluate/'.$offering->id : (Filament::getTenant() ? '/'.Filament::getTenant()->id : null));

        return DB::transaction(function () use ($students, $assessment, $requestIds, $actor, $destination) {
            $pending = self::pending($students->pluck('id')->all())->lockForUpdate()->get();
            if ($requestIds === null) {
                if ($pending->groupBy('user_id')->contains(fn ($requests) => $requests->count() > 1)) {
                    throw ValidationException::withMessages(['request_ids' => 'This ID has multiple waiting browsers. Select the exact browser verification code before approving.']);
                }
                $requestIds = $pending->pluck('id')->all();
            }
            $requestIds = array_values(array_unique(array_map('intval', $requestIds)));
            abort_unless(array_diff($requestIds, $pending->pluck('id')->all()) === [], 403, 'Only the selected students\' current browser requests can be approved.');

            return DB::table('student_sign_in_requests')->whereIn('id', $requestIds)
                ->whereNull('approved_at')->whereNull('consumed_at')->where('expires_at', '>', now())
                ->update(['approved_by' => $actor->id, 'approved_at' => now(), 'destination' => $destination,
                    'quiz_id' => $assessment?->id, 'updated_at' => now()]);
        });
    }

    private static function pending(array $userIds)
    {
        return DB::table('student_sign_in_requests')->whereIn('user_id', $userIds)
            ->whereNull('consumed_at')->whereNull('approved_at')->where('expires_at', '>', now());
    }

    public static function requestOptions(Collection $students): array
    {
        $names = $students->pluck('name', 'id');

        return self::pending($students->pluck('id')->all())->orderBy('created_at')->get()
            ->mapWithKeys(fn ($request) => [$request->id => ($names[$request->user_id] ?? 'Student').' · '.($request->verification_code ?? 'Request #'.$request->id).' · '.$request->created_at])->all();
    }

    /** Preselect only an unambiguous request; never guess between two browsers. */
    public static function unambiguousRequests(Collection $students): array
    {
        return self::pending($students->pluck('id')->all())->get()->groupBy('user_id')
            ->filter(fn ($requests) => $requests->count() === 1)->flatten()->pluck('id')->all();
    }

    public static function pendingCount(int $userId): int
    {
        return DB::table('student_sign_in_requests')->where('user_id', $userId)
            ->whereNull('consumed_at')->whereNull('approved_at')->where('expires_at', '>', now())->count();
    }
}
