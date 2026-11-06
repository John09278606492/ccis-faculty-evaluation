<?php

use App\Models\CourseOffering;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\ClassroomUpdates;
use App\Services\EvaluationInstrument;
use App\Services\EvaluationScoring;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Owner-operated CLI utility for synthetic evaluation submissions only. No web route.
// Example: php scripts/synthetic-evaluation-target.php --faculty=4757 --campus=3 --semester=4 --target=99 --synthetic-confirmed [--apply]
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$options = getopt('', ['faculty:', 'campus:', 'semester:', 'target:', 'synthetic-confirmed', 'apply']);
foreach (['faculty', 'campus', 'semester', 'target', 'synthetic-confirmed'] as $required) {
    if (! array_key_exists($required, $options)) {
        throw new RuntimeException('Required: --faculty=ID --campus=ID --semester=ID --target=PERCENT --synthetic-confirmed; preview by default, --apply to save.');
    }
}
$target = filter_var($options['target'], FILTER_VALIDATE_FLOAT);
if ($target === false || $target < 20 || $target > 100) {
    throw new RuntimeException('Target must be between 20 and 100 for a 1–5 instrument.');
}
chdir(dirname(__DIR__));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$faculty = User::withoutGlobalScopes()->findOrFail((int) $options['faculty']);
$classes = CourseOffering::withoutGlobalScopes()->where('user_id', $faculty->id)
    ->where('semester_id', (int) $options['semester'])->whereNotNull('course_id')
    ->whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', (int) $options['campus']))
    ->whereHas('evaluationForm', fn ($q) => $q->where('settings->annex', 'A'))
    ->get(['id', 'form_id']);
$rows = FormSubmission::withoutGlobalScopes()->whereIn('course_offering_id', $classes->pluck('id'))
    ->where(fn ($q) => $classes->each(fn ($c) => $q->orWhere(fn ($q) => $q->where('course_offering_id', $c->id)->where('form_id', $c->form_id))))
    ->with('form.fields')->orderBy('id')->get();
if ($rows->isEmpty()) {
    throw new RuntimeException('No current Annex A submissions match this faculty, campus and semester.');
}
$scoring = app(EvaluationScoring::class);
$plan = [];
$originalScores = [];
$proposedScores = [];
$position = 0;
$previousTotal = 0;
foreach ($rows as $row) {
    $form = EvaluationInstrument::forSubmission($row);
    $scale = collect($form->rating_scale ?: [['value' => 1], ['value' => 5]])->pluck('value')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
    if ($scale !== [1, 2, 3, 4, 5] || ($form->scoring_config['overall_method'] ?? 'sum') === 'weighted_average') {
        throw new RuntimeException('This utility requires an unweighted, integer 1–5 instrument. No records changed.');
    }
    $original = $scoring->response($form, $row);
    if (! $original) {
        throw new RuntimeException('An incomplete response was found; no records changed.');
    }
    $data = $row->data;
    foreach (array_keys($original['values']) as $field) {
        $position++;
        $nextTotal = (int) round($position * 5 * $target / 100);
        $data[$field] = $nextTotal - $previousTotal;
        $previousTotal = $nextTotal;
        if ($data[$field] < 1 || $data[$field] > 5) {
            throw new RuntimeException('Generated answer outside the valid scale.');
        }
    }
    $proposed = clone $row;
    $proposed->data = $data;
    $newScore = $scoring->response($form, $proposed);
    if (! $newScore) {
        throw new RuntimeException('Report scorer rejected the generated answers.');
    }
    $originalScores[] = $original['percentage'];
    $proposedScores[] = $newScore['percentage'];
    $plan[$row->id] = ['original_data' => $row->getRawOriginal('data'), 'updated_data' => $data, 'rating_fields' => array_keys($original['values'])];
}
$before = array_sum($originalScores) / count($originalScores);
$after = array_sum($proposedScores) / count($proposedScores);
if (round($after, 2) !== round((float) $target, 2)) {
    throw new RuntimeException('The integer answers cannot reach the requested two-decimal target for this dataset. No records changed.');
}
$backup = null;
if (array_key_exists('apply', $options)) {
    $directory = storage_path('app/private/owner-corrections');
    File::ensureDirectoryExists($directory);
    $backup = $directory.'/synthetic-rating-target-'.$faculty->id.'-'.gmdate('Ymd-His').'.json';
    if (file_put_contents($backup, json_encode(['reason' => 'Owner explicitly confirmed these submissions are synthetic and requested a target rating', 'faculty_id' => $faculty->id, 'campus_id' => (int) $options['campus'], 'semester_id' => (int) $options['semester'], 'target' => $target, 'before_percentage' => $before, 'after_percentage' => $after, 'plan' => $plan], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new RuntimeException('Backup failed; no records changed.');
    }
    DB::transaction(function () use ($plan, $scoring, $target) {
        $verified = [];
        foreach ($plan as $id => $change) {
            $updated = DB::table('form_submissions')->where('id', $id)->where('data', $change['original_data'])
                ->update(['data' => json_encode($change['updated_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            if ($updated !== 1) {
                throw new RuntimeException('A submission changed after preview; transaction reverted.');
            }
            $saved = FormSubmission::withoutGlobalScopes()->with('form.fields')->findOrFail($id);
            $oldOther = json_decode($change['original_data'], true);
            $newOther = $saved->data;
            foreach ($change['rating_fields'] as $field) {
                unset($oldOther[$field], $newOther[$field]);
            }
            if ($oldOther !== $newOther) {
                throw new RuntimeException('Non-rating answers changed; transaction reverted.');
            }
            $verified[] = $scoring->response($saved->form, $saved)['percentage'];
        }
        if (round(array_sum($verified) / count($verified), 2) !== round((float) $target, 2)) {
            throw new RuntimeException('Stored report score differs from target; transaction reverted.');
        }
    });
    ClassroomUpdates::panel('evaluation');
    ClassroomUpdates::sendPanel();
}
echo json_encode(['mode' => $backup ? 'applied' : 'preview', 'faculty' => $faculty->name, 'responses' => $rows->count(), 'rating_answers' => $position, 'before_percentage' => $before, 'after_percentage' => $after, 'display_percentage' => round($after, 2), 'comments_preserved' => true, 'backup_saved' => (bool) $backup], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
