<?php

namespace App\Services;

use App\Models\Campus;
use App\Models\Form;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EvaluationTemplates
{
    public const NAMES = ['A' => 'Annex A — Student Evaluation of Teachers (SET)', 'B' => 'Annex B — Supervisor’s Evaluation of Faculty (SEF)'];

    public function create(string $annex, Campus $campus): Form
    {
        abort_unless(auth()->guest() || auth()->user()->canAccessTenant($campus), 403);
        abort_unless(isset(self::NAMES[$annex]), 422);
        $source = json_decode(ltrim(file_get_contents(resource_path('data/faculty-evaluation-annexes.json')), "\xEF\xBB\xBF"), true, flags: JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($annex, $campus, $source) {
            $form = Form::create([
                'name' => self::NAMES[$annex], 'is_active' => false, 'requires_login' => true,
                'description' => 'Read each benchmark statement carefully and rate the faculty using the scale below. Your feedback is strictly confidential.',
                'evaluator_roles' => $annex === 'A' ? ['student'] : [],
                'rating_scale' => [
                    ['value' => 5, 'label' => 'Always manifested', 'description' => 'Evident in nearly all relevant situations (91-100% of instances).'],
                    ['value' => 4, 'label' => 'Often manifested', 'description' => 'Evident most of the time, with occasional lapses (61-90%).'],
                    ['value' => 3, 'label' => 'Sometimes manifested', 'description' => 'Evident about half the time (31-60%).'],
                    ['value' => 2, 'label' => 'Seldom manifested', 'description' => 'Infrequently Demonstrated: Rarely evident in relevant situations (11-30%).'],
                    ['value' => 1, 'label' => 'Never/Rarely manifested', 'description' => 'Seldom Demonstrated: Almost never evident, with only isolated cases (0-10%).'],
                ],
                'scoring_config' => ['section_method' => 'sum', 'overall_method' => 'sum', 'field_comments' => 'hidden', 'overall_comment' => 'optional'],
                'settings' => ['annex' => $annex, 'instrument' => $annex === 'A' ? 'SET' : 'SEF', 'source_maximum' => 75, 'section_descriptions' => $annex === 'A' ? ($source['descriptions'] ?? []) : []],
            ]);
            $form->campuses()->attach($campus);
            foreach ($source[$annex] as $item) {
                $form->fields()->create(['name' => 'criterion_'.$item['number'], 'label' => $item['label'], 'section' => $item['section'], 'type' => 'likert_scale', 'order' => $item['number'], 'is_required' => true, 'help_text' => $item['verification'] ?: null]);
            }

            return $form;
        });
    }

    public function duplicate(Form $source, Campus $campus, string $name): Form
    {
        abort_unless($source->campuses()->whereKey($campus)->exists(), 403);

        return DB::transaction(function () use ($source, $campus, $name) {
            $copy = $source->replicate();
            $copy->fill(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(8), 'is_active' => false, 'starts_at' => null, 'ends_at' => null, 'max_submissions' => null, 'previous_version_id' => $source->id, 'version' => ($source->version ?? 1) + 1, 'published_at' => null, 'archived_at' => null]);
            $copy->save();
            $copy->campuses()->attach($campus);
            $copy->departments()->sync($source->departments()->where('campus_id', $campus->id)->pluck('departments.id'));
            if (auth()->check()) {
                $ids = app(AssignedDataAccess::class)->departmentIds(auth()->user(), $campus);
                if ($ids !== null && $ids !== []) {
                    $copy->departments()->sync($ids);
                }
            }
            foreach ($source->fields()->get() as $field) {
                $copy->fields()->save($field->replicate());
            }

            return $copy;
        });
    }
}
