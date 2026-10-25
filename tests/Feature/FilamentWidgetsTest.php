<?php

use App\Filament\Pages\DepartmentReport;
use App\Filament\Pages\EvaluationResults;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\Pages\ListCourseOfferings;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\EvaluationReportResource\Pages\ListEvaluationReports;
use App\Filament\Resources\FormResource\Pages\ListForms;
use App\Filament\Resources\SchoolYearResource\Pages\EditSchoolYear;
use App\Filament\Resources\SchoolYearResource\Pages\ListSchoolYears;
use App\Filament\Resources\SchoolYearResource\RelationManagers\SemestersRelationManager;
use App\Filament\Widgets\DepartmentOverviewChart;
use App\Filament\Widgets\EvaluationCompletionChart;
use App\Filament\Widgets\EvaluationSubmissionsChart;
use App\Filament\Widgets\InstructorEvaluationSummaryWidget;
use App\Filament\Widgets\LatestUsersWidget;
use App\Filament\Widgets\StudentPendingEvaluationsWidget;
use App\Filament\Widgets\UserRegistrationChart;
use App\Models\Campus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The panel's tables, reports and widgets.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function reportAdmin(array $permissions = []): User
{
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
    foreach ($permissions as $permission) {
        $admin->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $admin;
}

it('lists the evaluation assignments of the campus', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_course::offering', 'view_course::offering']));

    Livewire::test(ListCourseOfferings::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering]);
});

it('fills the department of the course when opening an assignment', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_course::offering', 'view_course::offering', 'update_course::offering']));

    Livewire::test(EditCourseOffering::class, ['record' => $offering->id])
        ->assertSuccessful()
        ->assertFormFieldExists('department_id');
});

it('adds a student to the class list', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_course::offering', 'view_course::offering', 'update_course::offering']));

    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $offering, 'pageClass' => EditCourseOffering::class])
        ->assertCanSeeTableRecords([$offering->students->first()]);
});

it('shows the pending evaluations of a student', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $student->givePermissionTo(Permission::findOrCreate('widget_StudentPendingEvaluationsWidget', 'web'));
    $this->actingAs($student);

    Livewire::test(StudentPendingEvaluationsWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering]);
});

it('takes a pending evaluation off the list once it is sent', function () {
    ['student' => $student, 'offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $student->givePermissionTo(Permission::findOrCreate('widget_StudentPendingEvaluationsWidget', 'web'));
    FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-done',
        'user_id' => $student->id,
        'data' => ['teaching_quality' => 5],
    ]);
    $this->actingAs($student);

    Livewire::test(StudentPendingEvaluationsWidget::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$offering]);
});

it('summarises the evaluations of an instructor', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['widget_InstructorEvaluationSummaryWidget']));
    FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-1',
        'data' => ['teaching_quality' => 5],
    ]);

    Livewire::test(InstructorEvaluationSummaryWidget::class)->assertSuccessful();
});

it('shows the newest users to an administrator', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['widget_LatestUsersWidget']));

    Livewire::test(LatestUsersWidget::class)->assertSuccessful();
});

it('draws the charts of the dashboard', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin([
        'widget_EvaluationSubmissionsChart',
        'widget_EvaluationCompletionChart',
        'widget_DepartmentOverviewChart',
        'widget_UserRegistrationChart',
    ]));
    FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-1',
        'data' => ['teaching_quality' => 5],
    ]);

    Livewire::test(EvaluationSubmissionsChart::class)->assertSuccessful();
    Livewire::test(EvaluationCompletionChart::class)->assertSuccessful();
    Livewire::test(DepartmentOverviewChart::class)->assertSuccessful();
    Livewire::test(UserRegistrationChart::class)->assertSuccessful();
});

it('shows the evaluation report of the campus', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_form', 'view_form', 'page_EvaluationResults']));
    FormSubmission::create([
        'form_id' => $form->id,
        'course_offering_id' => $offering->id,
        'submission_fingerprint' => 'fp-1',
        'data' => ['teaching_quality' => 5],
    ]);

    Livewire::test(ListEvaluationReports::class)
        ->assertRedirect(EvaluationResults::getUrl());
    Livewire::test(EvaluationResults::class)
        ->assertSuccessful();
});

it('shows the department summary of the campus', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_department']));

    Livewire::test(DepartmentReport::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering->course->department]);
});

it('shows the forms of the campus', function () {
    ['offering' => $offering, 'form' => $form] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_form', 'view_form']));

    Livewire::test(ListForms::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$form]);
});

it('keeps the forms of another campus out of the list', function () {
    ['offering' => $offering] = evaluationFixture();
    $other = Campus::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'is_active' => true]);
    $foreign = Form::create(['name' => 'Foreign Form', 'slug' => 'foreign-'.uniqid(), 'is_active' => true]);
    $foreign->campuses()->attach($other->id);
    Filament::setTenant($offering->campus, isQuiet: true);
    // A super admin sees every campus's forms, so this checks an ordinary administrator.
    $admin = User::factory()->create();
    $admin->givePermissionTo(Permission::findOrCreate('view_any_form', 'web'));
    $admin->givePermissionTo(Permission::findOrCreate('view_form', 'web'));
    $admin->campuses()->attach($offering->campus->id);
    $this->actingAs($admin);

    Livewire::test(ListForms::class)
        ->assertSuccessful()
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('shows the school years of the campus', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_school::year', 'view_school::year']));

    Livewire::test(ListSchoolYears::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering->semester->schoolYear]);
});

it('lists the semesters of a school year', function () {
    ['offering' => $offering] = evaluationFixture();
    Filament::setTenant($offering->campus, isQuiet: true);
    $this->actingAs(reportAdmin(['view_any_school::year', 'view_school::year', 'update_school::year']));

    Livewire::test(SemestersRelationManager::class, [
        'ownerRecord' => $offering->semester->schoolYear,
        'pageClass' => EditSchoolYear::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$offering->semester]);
});
