<?php

namespace App\Http\Controllers;

use App\Models\AdvisorySubmission;
use App\Models\Campus;
use App\Services\Advising;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdvisoryFileController extends Controller
{
    public function __invoke(AdvisorySubmission $submission, int $index)
    {
        $user = auth()->user();
        $task = $submission->task()->with('advisoryClass')->firstOrFail();
        $campusId = app(Advising::class)->campusIdOf($task->advisoryClass);
        $isMember = DB::table('student_section_memberships')->where('user_id', $user->id)
            ->where('academic_section_id', $task->advisoryClass->academic_section_id)->exists();
        $owns = $user->id === $submission->user_id && $user->isStudent() && $user->can('page_MyAdvising') && $isMember && $task->status !== 'archived';
        abort_unless($user->canAccessTenant(Campus::findOrFail($campusId)) && ($owns || $user->can('view', $task)), 403);
        $path = ($submission->files ?? [])[$index] ?? null;
        $prefix = "advisory-submissions/{$task->id}/{$submission->user_id}/";
        abort_unless(is_string($path) && str_starts_with($path, $prefix) && ! str_contains($path, '..'), 404);
        $disk = Storage::disk(Advising::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->download($path, 'advising-file-'.($index + 1).'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
