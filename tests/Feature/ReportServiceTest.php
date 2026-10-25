<?php

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\ReportService;

beforeEach(function () {
    $this->service = app(ReportService::class);
});

it('counts anonymous roster responses once without exposing student identities', function () {
    ['offering' => $offering, 'form' => $form, 'student' => $student] = evaluationFixture();
    $fingerprint = app(EvaluationSubmissionIdentity::class)->fingerprint($offering, request(), $student);
    scoredSubmission($offering, $form, 4)->update(['submission_fingerprint' => $fingerprint]);
    scoredSubmission($offering, $form, 5)->update(['user_id' => $student->id, 'submission_fingerprint' => null]);
    $summary = $this->service->getEvaluateeSummary($offering->evaluatee);
    expect($summary['completion_rate'])->toBe(100.0)->and($summary['completed_students'])->toBe(1);
    expect($this->service->getDepartmentSummary($offering->course->department)['completion_rate'])->toBe(100.0);
});

it('keeps old form responses out of the current evaluation summary', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    scoredSubmission($offering, $form, 5);
    $replacement = Form::create(['name' => 'Replacement', 'is_active' => true]);
    $replacement->fields()->create(['name' => 'teaching_quality', 'label' => 'New criterion', 'type' => 'likert_scale', 'order' => 1]);
    $offering->update(['form_id' => $replacement->id]);
    scoredSubmission($offering, $replacement, 1);
    $summary = $this->service->getEvaluateeSummary($offering->evaluatee);
    expect($summary['total_submissions'])->toBe(1)->and($summary['avg_score'])->toBe(1.0);
});

/**
 * A submission with the given rating values for the fixture's likert field.
 */
function scoredSubmission($offering, $form, int $value, array $extra = []): FormSubmission
{
    return FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-'.uniqid(),
        'data' => array_merge(['teaching_quality' => $value], $extra),
        'submitted_at' => now(),
    ]);
}

it('averages the ratings of a submission', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->fields()->create(['name' => 'respect', 'label' => 'Respect', 'type' => 'likert_scale', 'is_required' => true, 'order' => 2]);
    $submission = scoredSubmission($offering, $form, 4);
    $submission->update(['data' => ['teaching_quality' => 5, 'respect' => 3]]);

    expect($this->service->calculateSubmissionScore($submission))->toBe(4.0);
});

it('keeps sum-configured forms on a normalized five-point reporting scale', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->update(['scoring_config' => ['overall_method' => 'sum']]);
    $form->fields()->create(['name' => 'second', 'label' => 'Second criterion', 'type' => 'likert_scale', 'order' => 2]);
    $submission = scoredSubmission($offering, $form, 5, ['second' => 3]);

    expect($this->service->calculateSubmissionScore($submission))->toBe(4.0);
});

it('has no score for a submission without numeric ratings', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $submission = scoredSubmission($offering, $form, 5);
    $submission->update(['data' => ['teaching_quality' => 'excellent']]);

    expect($this->service->calculateSubmissionScore($submission))->toBeNull();
});

it('ignores fields that are not ratings', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->fields()->create(['name' => 'comment', 'label' => 'Comment', 'type' => 'textarea', 'is_required' => false, 'order' => 2]);
    $submission = scoredSubmission($offering, $form, 4);
    $submission->update(['data' => ['teaching_quality' => 4, 'comment' => '5']]);

    expect($this->service->calculateSubmissionScore($submission))->toBe(4.0);
});

it('labels a score with the rating scale', function () {
    expect($this->service->getRatingLabel(5))->toBe('Outstanding')
        ->and($this->service->getRatingLabel(4))->toBe('Very Satisfactory')
        ->and($this->service->getRatingLabel(3))->toBe('Satisfactory')
        ->and($this->service->getRatingLabel(2))->toBe('Fair')
        ->and($this->service->getRatingLabel(1))->toBe('Poor');
});

it('uses the form\'s own rating scale for the label', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->update(['rating_scale' => [['value' => 10, 'label' => 'Perfect'], ['value' => 1, 'label' => 'Poor']]]);

    expect($this->service->getRatingLabel(9.5, $form->rating_scale))->toBe('Perfect')
        ->and($this->service->getRatingLabel(0.5, $form->rating_scale))->toBe('Poor');
});

it('summarises an evaluatee with their assignments and completion rate', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $evaluatee = $offering->evaluatee;
    scoredSubmission($offering, $form, 5);
    scoredSubmission($offering, $form, 3);

    $summary = $this->service->getEvaluateeSummary($evaluatee);

    expect($summary['avg_score'])->toBe(4.0)
        ->and($summary['total_submissions'])->toBe(2)
        ->and($summary['total_courses'])->toBe(1)
        ->and($summary['completion_rate'])->toBe(0.0)
        ->and($summary['course_offerings'])->toHaveCount(1);
});

it('has an empty summary for an evaluatee without assignments', function () {
    $user = User::factory()->create(['is_instructor' => true]);

    $summary = $this->service->getEvaluateeSummary($user);

    expect($summary['avg_score'])->toBeNull()
        ->and($summary['rating_label'])->toBe('—')
        ->and($summary['total_submissions'])->toBe(0)
        ->and($summary['total_courses'])->toBe(0)
        ->and($summary['completion_rate'])->toBe(0);
});

it('breaks the scores down per field and per section', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $form->fields()->create(['name' => 'respect', 'label' => 'Respect', 'type' => 'likert_scale', 'section' => 'Teaching', 'is_required' => true, 'order' => 2]);
    scoredSubmission($offering, $form, 4, ['respect' => 5]);
    scoredSubmission($offering, $form, 2, ['respect' => 3]);

    $fields = $this->service->getFieldBreakdown(collect([$offering->id]));

    expect($fields)->toHaveCount(2)
        ->and($fields->first()['field_name'])->toBe('teaching_quality')
        ->and($fields->first()['avg'])->toBe(3.0)
        ->and($fields->first()['min'])->toBe(2.0)
        ->and($fields->first()['max'])->toBe(4.0)
        ->and($fields->first()['count'])->toBe(2);

    $sections = $this->service->getSectionBreakdown(collect([$offering->id]));
    expect($sections)->not->toBeEmpty()
        ->and($sections->first()['field_count'])->toBeGreaterThanOrEqual(1);
});

it('has no field breakdown without submissions', function () {
    ['offering' => $offering] = evaluationFixture();

    expect($this->service->getFieldBreakdown(collect([$offering->id])))->toBeEmpty();
});

it('collects the comments students left', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    scoredSubmission($offering, $form, 5, [
        '_field_comments' => ['teaching_quality' => 'Clear explanations.'],
        '_overall_comment' => 'Very effective instructor.',
    ]);
    scoredSubmission($offering, $form, 4, [
        '_field_comments' => ['teaching_quality' => 'Too fast.'],
        '_overall_comment' => 'Good overall.',
    ]);

    $comments = $this->service->getAggregatedComments(collect([$offering->id]));

    expect($comments['field_comments']['teaching_quality'])->toHaveCount(2)
        ->and($comments['overall_comments'])->toHaveCount(2);
});

it('summarises a department by its instructors', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $department = $offering->course->department;
    scoredSubmission($offering, $form, 5);

    $summary = $this->service->getDepartmentSummary($department);

    expect($summary['department']->id)->toBe($department->id)
        ->and($summary['total_evaluatees'])->toBe(1)
        ->and($summary['total_submissions'])->toBe(1)
        ->and($summary['avg_score'])->toBe(5.0)
        ->and($summary['rating_label'])->toBe('Outstanding')
        ->and($summary['evaluatee_rankings'])->toHaveCount(1);
});

it('counts the scores by rating label', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    scoredSubmission($offering, $form, 5);
    scoredSubmission($offering, $form, 5);
    scoredSubmission($offering, $form, 1);

    $distribution = $this->service->getScoreDistribution(collect([$offering->id]));

    expect($distribution['Outstanding'])->toBe(2)
        ->and($distribution['Poor'])->toBe(1);
});
