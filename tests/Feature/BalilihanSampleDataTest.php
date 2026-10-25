<?php

use App\Models\Campus;
use App\Models\CourseOffering;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use Database\Seeders\BalilihanEvaluationSeeder;

it('prepares distinct campus departments and repeatable sample evaluation records', function () {
    evaluationFixture();
    $this->seed(BalilihanEvaluationSeeder::class);
    $campus = Campus::where('slug', 'balilihan')->sole();
    expect(Department::where('campus_id', $campus->id)->orderBy('code')->pluck('code')->all())->toBe(['CCIS', 'CCJ', 'CTAS']);
    expect(Department::where('campus_id', $campus->id)->orderBy('code')->pluck('name')->all())->toBe(['CCIS', 'CCJ', 'CTAS']);
    expect(CourseOffering::whereHas('semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->count())->toBe(9);
    expect(FormSubmission::whereHas('courseOffering.semester.schoolYear', fn ($q) => $q->where('campus_id', $campus->id))->count())->toBe(48);
    $counts = [User::count(), CourseOffering::count(), FormSubmission::count()];
    $this->seed(BalilihanEvaluationSeeder::class);
    expect([User::count(), CourseOffering::count(), FormSubmission::count()])->toBe($counts);
    $reviewer = User::where('email', 'sample.dean@balilihan.test')->sole();
    foreach (['CCJ', 'CTAS', 'CCIS'] as $code) {
        $assignment = CourseOffering::whereHas('course.department', fn ($q) => $q->where('campus_id', $campus->id)->where('code', $code))->where('evaluation_type', 'SET')->firstOrFail();
        $this->actingAs($reviewer)->get(route('evaluation.report', $assignment))->assertOk()->assertSee($assignment->evaluatee->name)->assertSee($code.'-SAMPLE-1')->assertDontSee('No results available');
        expect($assignment->students()->where('student_id', 'not like', 'BAL-'.$code.'-%')->exists())->toBeFalse();
    }
});
