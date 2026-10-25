<?php

namespace App\Services;

use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SectionRosterImports
{
    public function section(User $actor, Campus $campus, int $id): AcademicSection
    {
        $section = app(AcademicSections::class)->section($actor, $campus, $id, true, true);
        abort_unless($section->is_active, 422, 'Activate this section before uploading its student list.');

        return $section;
    }

    private function state(AcademicSection $section): string
    {
        return hash('sha256', json_encode($section->only(['id', 'department_id', 'semester_id', 'name', 'is_active'])));
    }

    public function prepare(User $actor, Campus $campus, int $id, string $source): array
    {
        $section = $this->section($actor, $campus, $id);
        $reader = app(TeachingClassRosterImports::class);
        $rows = $reader->read($source, $section->name);
        $analysis = $reader->analyzeRows($actor, $campus, $section->semester_id, $rows, $section->department_id, $section->name);

        return ['review' => [...$analysis['review'], 'target' => 'section', 'class_label' => $section->name,
            'course' => 'Section roster', 'faculty' => $section->department->code],
            'plan' => ['actor_id' => $actor->id, 'campus_id' => $campus->id, 'section_id' => $section->id, 'rows' => $rows,
                'state_hash' => $analysis['state_hash'], 'section_state' => $this->state($section)]];
    }

    public function commit(User $actor, Campus $campus, array $plan): array
    {
        abort_unless($plan['actor_id'] === $actor->id && $plan['campus_id'] === $campus->id, 403);

        return DB::transaction(function () use ($actor, $campus, $plan) {
            $section = $this->section($actor, $campus, $plan['section_id']);
            if (DB::getDriverName() === 'sqlite') {
                DB::table('academic_sections')->where('id', $section->id)->update(['id' => $section->id]);
            } else {
                AcademicSection::whereKey($section)->lockForUpdate()->firstOrFail();
            }
            $section->refresh();
            $analysis = app(TeachingClassRosterImports::class)->analyzeRows($actor, $campus, $section->semester_id, $plan['rows'], $section->department_id, $section->name);
            if ($analysis['review']['error_count'] || $analysis['state_hash'] !== $plan['state_hash'] || $this->state($section) !== $plan['section_state']) {
                throw ValidationException::withMessages(['file' => 'The section or masterlist changed after review. Upload and review the list again. No section memberships were changed.']);
            }
            $ids = array_keys($analysis['matches']);
            $added = app(AcademicSections::class)->add($actor, $campus, $section, $ids);

            return ['row_count' => count($ids), 'added' => $added, 'message' => $added.' students added to '.$section->name.'; '.(count($ids) - $added).' already belong to this section. Other section memberships and existing teaching-class evaluator lists were preserved. Select this saved section when assigning a Teaching Class.'];
        });
    }
}
