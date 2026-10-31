<?php

use App\Models\Form;
use App\Models\FormSubmission;
use App\Services\EvaluationInstrument;
use App\Services\EvaluationScoring;
use App\Services\EvaluationTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

it('locks published instrument content and criteria while allowing availability changes', function () {
    ['form' => $form] = evaluationFixture();
    $form->update(['published_at' => now()]);
    expect(fn () => $form->fresh()->update(['name' => 'Changed official instrument']))->toThrow(ValidationException::class);
    expect(fn () => $form->fields()->first()->update(['label' => 'Changed criterion']))->toThrow(ValidationException::class);
    expect(fn () => $form->fields()->first()->delete())->toThrow(ValidationException::class);
    expect(fn () => $form->fields()->create(['name' => 'new', 'label' => 'New', 'type' => 'likert_scale']))->toThrow(ValidationException::class);
    expect(fn () => $form->fresh()->delete())->toThrow(ValidationException::class);
    $form->fresh()->update(['is_active' => false, 'ends_at' => now()]);
    expect($form->fresh()->is_active)->toBeFalse();
});

it('locks a draft as soon as it has a response and retains an immutable scoring snapshot', function () {
    ['form' => $form, 'offering' => $offering] = evaluationFixture();
    $response = FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 4]]);
    expect($response->instrument_snapshot)->not->toBeNull();
    expect($form->fresh()->isLocked())->toBeTrue();
    expect(fn () => $form->fresh()->update(['rating_scale' => [['value' => 10]]]))->toThrow(ValidationException::class);
    // Simulate a historical external database edit; saved responses must still use their original instrument.
    DB::table('forms')->where('id', $form->id)->update(['name' => 'Unrelated later title', 'rating_scale' => json_encode([['value' => 10]])]);
    DB::table('form_fields')->where('form_id', $form->id)->update(['label' => 'Unrelated later criterion']);
    $snapshot = EvaluationInstrument::forSubmission($response->fresh());
    expect($snapshot->name)->toBe('Faculty Evaluation');
    expect($snapshot->fields->first()->label)->toBe('Teaching quality');
    expect(app(EvaluationScoring::class)->response($form->fresh(), $response->fresh()))->toMatchArray(['raw_total' => 4.0, 'maximum' => 5.0, 'percentage' => 80.0, 'mean_5' => 4.0]);
});

it('duplicates a locked template as an editable independent draft version', function () {
    ['form' => $form, 'offering' => $offering] = evaluationFixture();
    $form->departments()->attach($offering->course->department);
    $form->update(['published_at' => now()]);
    $copy = app(EvaluationTemplates::class)->duplicate($form, $offering->campus, 'Next official version');
    expect($copy->previous_version_id)->toBe($form->id);
    expect((int) $copy->version)->toBe(2);
    expect($copy->published_at)->toBeNull();
    expect($copy->is_active)->toBeFalse();
    expect($copy->isLocked())->toBeFalse();
    expect($copy->fields)->toHaveCount(1);
    expect($copy->fields->first()->id)->not->toBe($form->fields->first()->id);
    expect($copy->departments->pluck('id')->all())->toBe([$offering->course->department_id]);
    $copy->fields()->first()->update(['label' => 'Version two criterion']);
    expect($form->fields()->first()->label)->toBe('Teaching quality');
    expect($offering->fresh()->form_id)->toBe($form->id);
});

it('cannot unlock a published instrument or move its criteria into a draft', function () {
    ['form' => $form] = evaluationFixture();
    $draft = Form::create(['name' => 'Editable draft']);
    $form->update(['published_at' => now()]);
    expect(fn () => $form->fresh()->update(['published_at' => null]))->toThrow(ValidationException::class);
    expect(fn () => $form->fields()->first()->update(['form_id' => $draft->id]))->toThrow(ValidationException::class);
    expect($form->fields()->count())->toBe(1);
    expect($draft->fields()->count())->toBe(0);
});

it('keeps assigned draft instruments from being deleted and supports legacy submissions without snapshots', function () {
    ['form' => $form, 'offering' => $offering] = evaluationFixture();
    expect(fn () => $form->delete())->toThrow(ValidationException::class);
    $response = FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 5]]);
    DB::table('form_submissions')->where('id', $response->id)->update(['instrument_snapshot' => null]);
    expect(EvaluationInstrument::forSubmission($response->fresh())->id)->toBe($form->id);
});

it('allows prospective comment requirements while protecting published scores and snapshots', function () {
    ['form' => $form, 'offering' => $offering] = evaluationFixture();
    $form->update(['scoring_config' => ['overall_comment' => 'optional', 'overall_method' => 'sum']]);
    $form->update(['published_at' => now()]);
    $response = FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 4, '_overall_comment' => 'Original']]);
    $snapshot = $response->instrument_snapshot;
    $form->update(['scoring_config' => [...$form->scoring_config, 'overall_comment' => 'required']]);
    expect($response->fresh()->instrument_snapshot)->toBe($snapshot)
        ->and($response->fresh()->data['_overall_comment'])->toBe('Original')
        ->and(app(EvaluationScoring::class)->response($form->fresh(), $response->fresh())['raw_total'])->toBe(4.0);
    expect(fn () => $form->fresh()->update(['scoring_config' => ['overall_comment' => 'required', 'overall_method' => 'average']]))->toThrow(ValidationException::class);
    expect(fn () => $form->fresh()->update(['scoring_config' => ['overall_comment' => 'invalid', 'overall_method' => 'sum']]))->toThrow(ValidationException::class);
});
