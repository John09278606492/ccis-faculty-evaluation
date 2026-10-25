<?php

use App\Filament\Pages\EvaluationResults;
use App\Filament\Widgets\EvaluationCompletionChart;
use App\Models\Course;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\EvaluationScoring;
use App\Services\EvaluationTemplates;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

it('reports a summed Annex response in explicit units and excludes incomplete answers', function () {
    ['offering' => $offering] = evaluationFixture();
    $form = app(EvaluationTemplates::class)->create('A', $offering->campus);
    $offering->update(['form_id' => $form->id]);
    $answers = $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => $answers]);
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => []]);
    $score = app(EvaluationScoring::class)->assignment($offering);
    expect($score['count'])->toBe(1)->and($score['incomplete_count'])->toBe(1)
        ->and($score['mean_total'])->toBe(60.0)->and($score['maximum'])->toBe(75.0)
        ->and($score['mean_5'])->toBe(4.0)->and($score['percentage'])->toBe(80.0);
    $summary = app(ReportService::class)->getEvaluateeSummary($offering->evaluatee);
    expect($summary['avg_score'])->toBe(4.0)->and($summary['rating_label'])->toBe('Very Satisfactory');
});

it('weights complete respondents across assignments and keeps SET and SEF separate', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 5]]);
    $other = $offering->replicate();
    $other->section = 'B';
    $other->save();
    foreach ([1, 1, 1] as $value) {
        FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $other->id, 'data' => ['teaching_quality' => $value]]);
    }
    $supervisorForm = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $supervisor = $offering->replicate();
    $supervisor->form_id = $supervisorForm->id;
    $supervisor->section = 'SEF';
    $supervisor->save();
    $answers = $supervisorForm->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    FormSubmission::create(['form_id' => $supervisorForm->id, 'course_offering_id' => $supervisor->id, 'data' => $answers]);
    $summary = app(ReportService::class)->getEvaluateeSummary($offering->evaluatee);
    expect($summary['avg_score'])->toBeNull()->and($summary['instruments']['SET']['mean_5'])->toBe(2.0)
        ->and($summary['instruments']['SEF']['mean_5'])->toBe(4.0);
});

it('includes visiting faculty only for the selected teaching department', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 5]]);
    $host = Department::create(['campus_id' => $offering->campus->id, 'name' => 'Host Department', 'code' => 'HOST', 'is_active' => true]);
    $course = Course::create(['department_id' => $host->id, 'name' => 'Host Course', 'code' => 'HOST1', 'is_active' => true]);
    $visiting = $offering->replicate();
    $visiting->course_id = $course->id;
    $visiting->save();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $visiting->id, 'data' => ['teaching_quality' => 1]]);
    $summary = app(ReportService::class)->getDepartmentSummary($host);
    expect($summary['total_evaluatees'])->toBe(1)->and($summary['total_submissions'])->toBe(1)->and($summary['avg_score'])->toBe(1.0);
});

it('uses the selected semester in the main table and its CSV export', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    $offering->evaluatee->campuses()->attach($offering->campus);
    $secondSemester = $offering->semester->replicate();
    $secondSemester->name = 'Second Semester';
    $secondSemester->save();
    $second = $offering->replicate();
    $second->semester_id = $secondSemester->id;
    $second->save();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'data' => ['teaching_quality' => 5]]);
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $second->id, 'data' => ['teaching_quality' => 1]]);
    $viewer = User::factory()->create(['is_student' => false, 'instructor_department_id' => $offering->course->department_id]);
    $viewer->campuses()->attach($offering->campus);
    $viewer->givePermissionTo(Permission::findOrCreate('page_EvaluationResults', 'web'));
    $this->actingAs($viewer);
    Filament::setTenant($offering->campus);
    $component = Livewire::test(EvaluationResults::class)->filterTable('semester', $secondSemester->id)
        ->assertTableColumnStateSet('set_rating', 1.0, $offering->evaluatee)
        ->assertTableColumnStateSet('evaluations_count', 1, $offering->evaluatee)
        ->callTableAction('export_csv')->assertHasNoTableActionErrors();
    $task = \App\Models\BackgroundTask::where('kind', 'faculty-export')->sole();
    app(\App\Services\BackgroundTasks::class)->run($task->id);
    expect($task->fresh()->status)->toBe('completed', $task->fresh()->error ?? '');
    $csv = \Illuminate\Support\Facades\Storage::disk('local')->get($task->fresh()->file_path);
    expect($csv)->toContain('SET,1.00,20.00,1')->not->toContain('SET,5.00,100.00');
});

it('counts unique SET roster completion and excludes supervisor tasks from the dashboard chart', function () {
    ['offering' => $offering, 'form' => $form, 'student' => $student] = evaluationFixture();
    $second = User::factory()->create(['is_student' => true]);
    $second->campuses()->attach($offering->campus);
    $offering->students()->attach($second);
    foreach ([3, 4, 5] as $rating) {
        FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $offering->id, 'user_id' => $student->id, 'data' => ['teaching_quality' => $rating]]);
    }
    $supervisorForm = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $supervisor = $offering->replicate();
    $supervisor->form_id = $supervisorForm->id;
    $supervisor->section = 'SEF';
    $supervisor->save();
    $viewer = User::factory()->create();
    $viewer->assignRole('super_admin');
    $viewer->campuses()->attach($offering->campus);
    $this->actingAs($viewer);
    Filament::setTenant($offering->campus);
    $chart = new EvaluationCompletionChart;
    $method = new ReflectionMethod($chart, 'getData');
    expect($method->invoke($chart)['datasets'][0]['data'])->toBe([0, 1, 0]);
});

it('reports no-course supervisor tasks under their explicit teaching department', function () {
    ['offering' => $offering] = evaluationFixture();
    $host = Department::create(['campus_id' => $offering->campus->id, 'name' => 'Host Department', 'code' => 'HOST', 'is_active' => true]);
    $form = app(EvaluationTemplates::class)->create('B', $offering->campus);
    $task = $offering->replicate();
    $task->course_id = null;
    $task->teaching_department_id = $host->id;
    $task->form_id = $form->id;
    $task->section = 'SEF-HOST';
    $task->save();
    $answers = $form->fields()->pluck('name')->mapWithKeys(fn ($name) => [$name => 4])->all();
    FormSubmission::create(['form_id' => $form->id, 'course_offering_id' => $task->id, 'data' => $answers]);
    $summary = app(ReportService::class)->getDepartmentSummary($host);
    expect($summary['total_evaluatees'])->toBe(1)->and($summary['instruments']['SEF']['percentage'])->toBe(80.0);
    expect($summary['instruments'])->not->toHaveKey('SET');
});
