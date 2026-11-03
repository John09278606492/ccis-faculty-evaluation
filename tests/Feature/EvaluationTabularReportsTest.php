<?php

use App\Filament\Pages\EvaluationResults;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationInstrument;
use App\Services\EvaluationTabularReports;
use App\Services\EvaluationTally;
use App\Services\EvaluationTemplates;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    ['offering' => $this->assignment, 'student' => $this->student] = evaluationFixture();
    $this->campus = $this->assignment->campus;
    $this->staff = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->assignment->course->department_id]);
    $this->staff->campuses()->attach($this->campus);
    $this->staff->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->form = app(EvaluationTemplates::class)->create('A', $this->campus);
    $this->assignment->update(['form_id' => $this->form->id]);
    $this->actingAs($this->staff);
});

it('prints separate landscape scores and tally reports with total and average after the last question', function () {
    foreach ([4, 5] as $rating) {
        FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'user_id' => $this->student->id,
            'data' => $this->form->fields->mapWithKeys(fn ($field) => [$field->name => $rating])->all(),
            'evaluator_identity' => ['name' => 'Private Student Name', 'student_id' => 'PRIVATE-001']]);
    }
    $url = fn ($kind) => route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => $kind]);
    $scores = $this->get($url('scores'))->assertOk()->assertSee('EVALUATION SCORES REPORT')->assertSee('Question / Criterion')->assertSee('9.00')->assertSee('4.50')->assertSee('90.00')->assertSee('size:A4 landscape', false);
    $tally = $this->get($url('tally'))->assertOk()->assertSee('EVALUATION TALLY REPORT')->assertSee('Student 1')->assertSee('Student 2')->assertSee('Total Score')->assertSee('Average')->assertSee('60.00')->assertSee('75.00')->assertSee('4.00')->assertSee('5.00')->assertSee('Question averages');
    foreach ([$scores, $tally] as $response) {
        $response->assertDontSee('Private Student Name')->assertDontSee('PRIVATE-001')->assertDontSee($this->student->email)->assertDontSee('submission_fingerprint')->assertSee('Print / Save PDF');
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    }
    $tableHeader = explode('</thead>', explode('aria-label="Anonymous evaluator tally"', $tally->getContent())[1])[0];
    expect(strpos($tableHeader, '>Q15<'))->toBeLessThan(strpos($tableHeader, '>Total Score'));
    expect(strpos($tableHeader, '>Total Score'))->toBeLessThan(strpos($tableHeader, '>Average'));
    $this->student->delete();
    $this->get($url('tally'))->assertOk()->assertSee('Student 1')->assertSee('60.00');
    if (getenv('RENDER_TABULAR_REPORT_QA')) {
        file_put_contents(storage_path('app/qa/evaluation-scores-report.html'), $scores->getContent());
        file_put_contents(storage_path('app/qa/evaluation-tally-report.html'), $tally->getContent());
        $prototype = FormSubmission::first();
        for ($i = 2; $i < 120; $i++) {
            $prototype->replicate()->save();
        }
        file_put_contents(storage_path('app/qa/evaluation-tally-many-rows.html'), $this->get($url('tally'))->getContent());
    }
});

it('excludes missing and out-of-range ratings from totals and averages', function () {
    // Use a compact original-scale instrument for an exact 5 + 3 + 4 example.
    $form = Form::create(['name' => 'Three questions', 'rating_scale' => [['value' => 5], ['value' => 1]]]);
    foreach (['a', 'b', 'c'] as $index => $name) {
        $form->fields()->create(['name' => $name, 'label' => $name, 'type' => 'rating', 'order' => $index]);
    }
    $this->assignment->update(['form_id' => $form->id]);
    foreach ([['a' => 5, 'b' => 3, 'c' => 4], ['a' => 5], ['a' => 99, 'b' => 4, 'c' => 5]] as $data) {
        FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $form->id, 'data' => $data]);
    }
    $tally = app(EvaluationTally::class)->build($this->assignment->fresh(), $this->assignment->formSubmissions()->get());
    expect($tally['rows'][0])->toMatchArray(['total' => 12.0, 'average' => 4.0, 'percentage' => 80.0]);
    expect($tally['rows'][1]['average'])->toBeNull()->and($tally['rows'][2]['total'])->toBeNull();
    expect($tally['summary'])->toMatchArray(['complete' => 1, 'incomplete' => 2, 'grand_total' => 12.0,
        'question_totals' => [5.0, 3.0, 4.0], 'question_averages' => [5.0, 3.0, 4.0], 'mean_average' => 4.0]);
});

it('separates supervisor and student instruments and respects instrument, version and period filters', function () {
    FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $this->form->fields->mapWithKeys(fn ($field) => [$field->name => 5])->all()]);
    $supervisorForm = app(EvaluationTemplates::class)->create('B', $this->campus);
    $supervisor = $this->assignment->replicate();
    $supervisor->form_id = $supervisorForm->id;
    $supervisor->section = 'Supervisor assignment';
    $supervisor->save();
    FormSubmission::create(['course_offering_id' => $supervisor->id, 'form_id' => $supervisorForm->id, 'data' => $supervisorForm->fields->mapWithKeys(fn ($field) => [$field->name => 3])->all()]);
    $period = $this->assignment->semester->replicate();
    $period->name = 'Other period';
    $period->save();
    $other = $this->assignment->replicate();
    $other->semester_id = $period->id;
    $other->save();
    $data = app(EvaluationTabularReports::class)->build($this->staff, $this->assignment);
    expect($data)->toHaveCount(2)->and($data[0]['score']['mean_5'])->toBe(5.0)->and($data[1]['score']['mean_5'])->toBe(3.0)
        ->and($data[1]['tally']['rows'][0]['label'])->toBe('Supervisor');
    $response = $this->get(route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => 'tally', 'instrument' => 'SEF']))->assertOk()->assertSee('Supervisor')->assertDontSee('Student 1')->assertDontSee('Other period');
    expect($response->viewData('reports'))->toHaveCount(1);
    expect(app(EvaluationTabularReports::class)->build($this->staff, $this->assignment, ['form' => $supervisorForm->id]))->toHaveCount(1);
});

it('keeps submitted question snapshots and stable anonymous numbering across historical revisions', function () {
    $field = $this->form->fields->first();
    $original = $field->question_text;
    $answers = $this->form->fields->mapWithKeys(fn ($item) => [$item->name => 4])->all();
    FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $answers]);
    DB::table('form_fields')->where('id', $field->id)->update(['label' => 'Historical revision criterion']);
    FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $answers]);
    $reports = app(EvaluationTabularReports::class)->build($this->staff, $this->assignment->fresh());
    expect($reports)->toHaveCount(2)->and($reports[0]['tally']['questions'][0]['label'])->toBe($original)
        ->and($reports[1]['tally']['questions'][0]['label'])->toBe('Historical revision criterion')
        ->and($reports[0]['tally']['rows'][0]['label'])->toBe('Student 1')
        ->and($reports[1]['tally']['rows'][0]['label'])->toBe('Student 2');
});

it('denies students, missing reporting permission and foreign campus or department anchors', function () {
    $url = route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => 'tally']);
    $this->actingAs($this->student)->get($url)->assertForbidden();
    $this->staff->revokePermissionTo('page_EvaluationResults');
    $this->actingAs($this->staff)->get($url)->assertForbidden();
    $this->staff->givePermissionTo('page_EvaluationResults');
    $department = Department::create(['campus_id' => $this->campus->id, 'name' => 'Outside department', 'code' => 'OUTSIDE', 'is_active' => true]);
    $course = Course::create(['department_id' => $department->id, 'code' => 'OUTSIDE1', 'name' => 'Outside class', 'is_active' => true]);
    $other = $this->assignment->replicate();
    $other->course_id = $course->id;
    $other->save();
    $this->get(route('evaluation.tabular-report', ['offering' => $other, 'kind' => 'scores']))->assertForbidden();
    $this->get($url.'?department='.$department->id)->assertForbidden();
    $this->get($url)->assertOk()->assertDontSee('Outside class');
    $foreign = Campus::create(['name' => 'Foreign campus', 'slug' => 'foreign-tabular', 'is_active' => true]);
    $this->staff->campuses()->detach($this->campus);
    $this->staff->campuses()->attach($foreign);
    $this->get($url)->assertForbidden();
});

it('enforces closed-period and minimum-response rules for a faculty members own reports', function () {
    $faculty = $this->assignment->evaluatee;
    $faculty->campuses()->attach($this->campus);
    $faculty->givePermissionTo(Permission::findOrCreate('page_MyEvaluationResults', 'web'));
    $this->actingAs($faculty);
    config(['evaluations.min_responses_for_results' => 1]);
    $this->form->update(['is_active' => true]);
    $url = route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => 'scores']);
    $this->get($url)->assertForbidden();
    FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $this->form->fields->mapWithKeys(fn ($field) => [$field->name => 4])->all()]);
    $this->get($url)->assertForbidden();
    $this->form->update(['ends_at' => now()->subMinute()]);
    $this->get($url)->assertOk()->assertSee('80.00');
});

it('offers one report button with all report formats in the modal and carries the current table filters', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $component = Livewire::test(EvaluationResults::class)->filterTable('instrument', 'SET')->filterTable('form', $this->form->id);
    $table = $component->instance()->getTable();
    expect($table->getActions())->toHaveCount(1);
    expect($table->getAction('view_details')->getLabel())->toBe('View Reports');
    expect($table->getAction('view_details')->getUrl())->toBeNull();
    foreach (['scoresReport', 'tallyReport', 'reportPreview'] as $removed) {
        expect($table->getAction($removed))->toBeNull();
    }
    $component->mountTableAction('view_details', $this->assignment->evaluatee)
        ->assertSee('Scores &amp; Tally', false)->assertSee('Scores Report')->assertSee('Tally Report')
        ->assertSee('Annex C')->assertSee('Annex D')->assertSee('selectedReport')->assertSee('<iframe', false);
    $view = $component->instance()->getMountedTableAction()->getModalContent();
    $options = $view->getData()['reportOptions'];
    foreach (['scores', 'tally', 'annex_c', 'annex_d'] as $key) {
        parse_str(parse_url($options[$key]['url'], PHP_URL_QUERY), $filters);
        expect($filters['form'])->toBe((string) $this->form->id);
        if (in_array($key, ['scores', 'tally'], true)) {
            expect($filters['instrument'])->toBe('SET');
        } else {
            expect($filters['annex'])->toBe($key === 'annex_c' ? 'C' : 'D');
        }
    }
    if (getenv('RENDER_REPORT_BROWSER_QA')) {
        file_put_contents(storage_path('app/qa/faculty-report-browser.html'), $view->render());
    }
});

it('splits wide question sets into readable landscape panels and shows final totals after the final question', function () {
    $form = Form::create(['name' => 'Wide custom instrument', 'rating_scale' => [['value' => 5], ['value' => 1]]]);
    $form->campuses()->attach($this->campus);
    for ($number = 1; $number <= 34; $number++) {
        $form->fields()->create(['name' => 'q'.$number, 'label' => 'Question '.$number, 'type' => 'rating', 'order' => $number]);
    }
    $this->assignment->update(['form_id' => $form->id]);
    FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $form->id, 'data' => $form->fields->mapWithKeys(fn ($field) => [$field->name => 5])->all()]);
    $response = $this->get(route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => 'tally']))->assertOk()->assertSee('Q34')->assertSee('170.00');
    expect(substr_count($response->getContent(), 'aria-label="Anonymous evaluator tally"'))->toBe(3)
        ->and(substr_count($response->getContent(), '>Total Score<br>'))->toBe(1);
    if (getenv('RENDER_TABULAR_REPORT_QA')) {
        file_put_contents(storage_path('app/qa/evaluation-tally-wide.html'), $response->getContent());
    }
});

it('renders empty reports without inventing zero ratings', function () {
    $response = $this->get(route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => 'tally']))->assertOk()->assertSee('No submitted responses.');
    $summary = $response->viewData('reports')->first()['tally']['summary'];
    expect($summary['grand_total'])->toBeNull()->and($summary['mean_average'])->toBeNull();
    $this->get(route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => 'invalid']))->assertNotFound();
});

it('renders multiple legacy submissions with strict lazy-loading prevention enabled', function () {
    $answers = $this->form->fields->mapWithKeys(fn ($field) => [$field->name => 4])->all();
    foreach (range(1, 3) as $number) {
        FormSubmission::create(['course_offering_id' => $this->assignment->id, 'form_id' => $this->form->id, 'data' => $answers]);
    }
    DB::table('form_submissions')->update(['instrument_snapshot' => null]);
    $previous = Model::preventsLazyLoading();
    Model::preventLazyLoading(true);
    try {
        foreach (['scores', 'tally'] as $kind) {
            $this->get(route('evaluation.tabular-report', ['offering' => $this->assignment, 'kind' => $kind]))
                ->assertOk()->assertSee('80.00')->assertSee('60.00')->assertDontSee('SERVER ERROR');
        }
        $legacy = FormSubmission::with('courseOffering')->get()->first();
        expect(app(EvaluationInstrument::class)->forSubmission($legacy)->fields)->toHaveCount(15);
    } finally {
        Model::preventLazyLoading($previous);
    }
});
