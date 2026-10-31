<?php

use App\Models\FormSubmission;
use App\Services\SqliteMigration;
use Illuminate\Support\Facades\DB;

it('copies raw identities and all rows into a new verified SQLite database without changing the source', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $submission = FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id,
        'data' => ['teaching_quality' => 4], 'evaluator_identity' => ['name' => 'Private migration identity']]);
    $raw = $submission->getRawOriginal('evaluator_identity');
    $file = storage_path('framework/testing/copy-'.uniqid().'.sqlite');
    if (! is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    try {
        $report = app(SqliteMigration::class)->copy($file);
        expect($report['tables']['form_submissions']['rows'])->toBe(1);
        $pdo = new PDO('sqlite:'.$file);
        expect($pdo->query('SELECT evaluator_identity FROM form_submissions')->fetchColumn())->toBe($raw);
        expect($submission->fresh()->evaluator_identity['name'])->toBe('Private migration identity');
        expect(DB::getDefaultConnection())->toBe('sqlite');
        expect(fn () => app(SqliteMigration::class)->copy($file))->toThrow(RuntimeException::class);
    } finally {
        $pdo = null;
        foreach ([$file, $file.'-wal', $file.'-shm', $file.'.verification.json'] as $artifact) {
            if (is_file($artifact)) {
                unlink($artifact);
            }
        }
    }
});
