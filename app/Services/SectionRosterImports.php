<?php

namespace App\Services;

use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\StudentSectionMembership;
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
        // A title such as "BS CRIM 2B" above the list names this section in the file's own spelling.
        $titles = [];
        $rows = array_map(function ($row) use ($section, &$titles) {
            if ($row['section'] !== $section->name && AcademicSections::sameName($row['section'], $section->name)) {
                $titles[$row['section']] = true;
                $row['section'] = $section->name;
            }

            return $row;
        }, $reader->read($source, $section->name, true));
        $analysis = $reader->analyzeRows($actor, $campus, $section->semester_id, $rows, $section->department_id, $section->name);
        $comparison = $this->compare($actor, $campus, $section, array_keys($analysis['matches']));

        return ['review' => [...$analysis['review'], 'target' => 'section', 'class_label' => $section->name,
            'course' => 'Section roster', 'faculty' => $section->department->code, 'file_titles' => array_keys($titles),
            'comparison' => $comparison, 'nothing_new' => $analysis['review']['error_count'] === 0 && $comparison['new'] === []],
            'plan' => ['actor_id' => $actor->id, 'campus_id' => $campus->id, 'section_id' => $section->id, 'rows' => $rows,
                'state_hash' => $analysis['state_hash'], 'section_state' => $this->state($section)]];
    }

    /**
     * How the file differs from the section today, plus look-alike sections (BSCRIM 2B beside BSCRM 2B)
     * and other sections the new students already belong to, so a duplicate list is visible before it is added.
     */
    public function compare(User $actor, Campus $campus, AcademicSection $section, array $fileUserIds): array
    {
        $label = fn ($s) => ['id' => $s->student_id, 'name' => $s->name];
        $members = StudentSectionMembership::where('academic_section_id', $section->id)->pluck('user_id')->all();
        $new = array_values(array_diff($fileUserIds, $members));
        $missing = array_values(array_diff($members, $fileUserIds));
        $users = User::whereKey([...$new, ...$missing])->get(['id', 'student_id', 'name'])->keyBy('id');
        $elsewhere = StudentSectionMembership::whereIn('user_id', $new)->where('semester_id', $section->semester_id)
            ->where(fn ($q) => $q->whereNull('academic_section_id')->orWhere('academic_section_id', '!=', $section->id))
            ->get(['user_id', 'section'])->groupBy('section')
            ->map(fn ($rows, $name) => ['section' => $name, 'students' => $rows->map(fn ($row) => $label($users[$row->user_id]))->sortBy('id')->values()->all()])
            ->values()->all();
        $similar = app(AcademicSections::class)->query($actor, $campus)->where('semester_id', $section->semester_id)->whereKeyNot($section->id)
            ->with('department')->get()->filter(fn ($other) => AcademicSections::sameName($other->name, $section->name))
            ->map(function ($other) use ($fileUserIds) {
                $ids = StudentSectionMembership::where('academic_section_id', $other->id)->pluck('user_id')->all();

                return ['section' => $other->department->code.' · '.$other->name, 'students' => count($ids), 'in_file' => count(array_intersect($ids, $fileUserIds))];
            })->values()->all();

        return ['current' => count($members), 'in_file' => count($fileUserIds), 'already' => count($fileUserIds) - count($new),
            'new' => collect($new)->map(fn ($id) => $label($users[$id]))->sortBy('id')->values()->all(),
            'missing' => collect($missing)->map(fn ($id) => $label($users[$id]))->sortBy('id')->values()->all(),
            'elsewhere' => $elsewhere, 'similar' => $similar];
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
            $synced = app(InstructorOfferingImports::class)->syncSection($actor, $campus, $section);

            return ['row_count' => count($ids), 'added' => $added, 'synced_classes' => $synced, 'message' => $added.' students added to '.$section->name.'; '.(count($ids) - $added).' already belong to this section. '.$synced.' imported draft class rosters updated. Prepared evaluations and customized evaluator lists were preserved.'];
        });
    }
}
