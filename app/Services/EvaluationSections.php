<?php

namespace App\Services;

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\StudentSectionMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EvaluationSections
{
    public const PENDING = 'Section pending';

    public function requiresSection(CourseOffering $class): bool
    {
        if (! $class->course_id) return false;

        return CourseOffering::withoutGlobalScope('assigned_data')->where('course_id', $class->course_id)->where('semester_id', $class->semester_id)
            ->where('is_active', true)->where(fn ($q) => $q->whereDoesntHave('evaluationForm')->orWhereHas('evaluationForm', fn ($f) => $f->withoutGlobalScope('assigned_data')->where('settings->annex', '!=', 'B')->orWhereNull('settings->annex')))
            ->distinct()->count('user_id') > 1;
    }

    public function forStudent(CourseOffering $class, User $student, bool $requireMatch = false): ?string
    {
        $saved = $class->evaluator_selection['report_sections'][$student->id] ?? null;
        if (filled($saved)) {
            return $saved;
        }
        $members = $student->relationLoaded('studentSectionMemberships') ? $student->studentSectionMemberships->where('semester_id', $class->semester_id)
            : StudentSectionMembership::where('user_id', $student->id)->where('semester_id', $class->semester_id)->get();
        $groups = $class->evaluator_selection['sections'] ?? [];
        $matches = $members->filter(fn ($m) => in_array($m->department_id.'~'.$m->semester_id.'~'.rawurlencode($m->section), $groups, true)
            || ((int) $m->department_id === (int) ($class->teaching_department_id ?? $class->course?->department_id)
                && filled($class->section) && TeachingClassRosterImports::section($m->section) === TeachingClassRosterImports::section($class->section)));
        // An irregular student may belong to several sections. Do not guess one.
        $names = ($requireMatch || $matches->isNotEmpty() ? $matches : $members)->pluck('section')->unique()->values();

        return $names->count() === 1 ? $names->first() : null;
    }

    public function label(FormSubmission $submission, CourseOffering $class): string
    {
        if (array_key_exists('_evaluation_context', $submission->data ?? [])) {
            return ($submission->data['_evaluation_context']['section'] ?? null) ?: self::PENDING;
        }

        // Existing reports keep their historical class label until explicitly classified.
        return $class->evaluation_section_label;
    }

    public function assign(User $actor, CourseOffering $class, array $studentIds, int $sectionId): array
    {
        return DB::transaction(function () use ($actor, $class, $studentIds, $sectionId) {
            if (DB::getDriverName() === 'sqlite') {
                DB::table('course_offerings')->where('id', $class->id)->update(['id' => $class->id]);
            } else {
                CourseOffering::whereKey($class->id)->lockForUpdate()->firstOrFail();
            }
            $class = $class->fresh();
            abort_unless($actor->can('manageEvaluators', $class) && ! $class->evaluationForm?->isSupervisorInstrument(), 403);
            $ids = array_values(array_unique(array_map('intval', $studentIds)));
            abort_unless($ids && app(AssignedDataAccess::class)->apply(CourseOffering::query(), $actor, $class->campus)->whereKey($class->id)->exists()
                && $class->students()->whereKey($ids)->count() === count($ids), 403);
            $section = app(AcademicSections::class)->section($actor, $class->campus, $sectionId, true);
            abort_unless((int) $section->semester_id === (int) $class->semester_id, 422, 'Choose a section in this class academic period.');
            if (app(AcademicSections::class)->eligible($actor, $class->campus, $section)->whereKey($ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['section_id' => 'Choose students enrolled in this period and belonging to this section department. Assign other departments separately.']);
            }
            app(AcademicSections::class)->add($actor, $class->campus, $section, $ids);
            $selection = $class->evaluator_selection ?? [];
            $labels = $selection['report_sections'] ?? [];
            foreach ($ids as $id) {
                $labels[$id] = $section->name;
            }
            if (isset($selection['auto_match'])) $selection['auto_match']['pending_section'] = $class->students()->pluck('users.id')->filter(fn ($id) => empty($labels[$id]))->count();
            $class->update(['evaluator_selection' => [...$selection, 'report_sections' => $labels, 'auto_section_roster' => false]]);
            $fingerprints = collect($ids)->map(fn ($id) => app(EvaluationSubmissionIdentity::class)->forUser($class, $id))->all();
            $classified = 0;
            // Only fill previously pending sections; known historical sections and all
            // ratings/comments remain untouched. No evaluator names enter reports.
            foreach ($class->formSubmissions()->withoutGlobalScope('assigned_data')->whereIn('submission_fingerprint', $fingerprints)->get() as $submission) {
                $data = $submission->data ?? [];
                if (! array_key_exists('_evaluation_context', $data) || filled($data['_evaluation_context']['section'] ?? null)) {
                    continue;
                }
                $data['_evaluation_context'] = [...$data['_evaluation_context'], 'section' => $section->name,
                    'section_assigned_at' => now()->toIso8601String(), 'section_assigned_by' => $actor->id];
                $submission->update(['data' => $data]);
                $classified++;
            }
            ClassroomUpdates::panel('setup');

            return ['students' => count($ids), 'responses' => $classified];
        });
    }
}
