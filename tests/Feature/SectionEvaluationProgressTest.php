<?php

use App\Filament\Pages\StudentEvaluationProgress;
use App\Models\AcademicSection;
use App\Models\Campus;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\EvaluationSubmissionIdentity;
use App\Services\StudentEvaluationProgress as Progress;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['student' => $this->student, 'offering' => $this->offering, 'form' => $this->form] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->department = $this->offering->course->department;
    $this->student->update(['student_department_id' => $this->department->id]);
    $this->head = User::factory()->create(['is_student' => false]);
    $this->head->campuses()->attach($this->campus);
    $this->head->assignRole(Role::findOrCreate('chairperson', 'web'));
    $this->department->update(['chairperson_id' => $this->head->id]);
    $this->head->givePermissionTo(Permission::findOrCreate('page_StudentEvaluationProgress', 'web'));
    $this->section = AcademicSection::create(['department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 3A', 'is_active' => true]);
    $this->otherSection = AcademicSection::create(['department_id' => $this->department->id, 'semester_id' => $this->offering->semester_id, 'name' => 'BSIT 3B', 'is_active' => true]);
    $this->member = function (User $student, ?AcademicSection $section = null) {
        $section ??= $this->section;
        StudentSectionMembership::create(['user_id' => $student->id, 'academic_section_id' => $section->id,
            'department_id' => $section->department_id, 'semester_id' => $section->semester_id, 'section' => $section->name]);
    };
    ($this->member)($this->student);
    ($this->member)($this->student, $this->otherSection);
    $this->pending = User::factory()->create(['is_student' => true, 'student_department_id' => $this->department->id]);
    $this->pending->assignRole('student');
    $this->pending->campuses()->attach($this->campus);
    $this->offering->students()->attach($this->pending);
    ($this->member)($this->pending);
    $this->unassigned = User::factory()->create(['is_student' => true, 'student_department_id' => $this->department->id]);
    $this->unassigned->assignRole('student');
    $this->unassigned->campuses()->attach($this->campus);
    ($this->member)($this->unassigned);
    FormSubmission::create(['course_offering_id' => $this->offering->id, 'form_id' => $this->form->id,
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->fingerprint($this->offering, request(), $this->student),
        'data' => ['teaching_quality' => 1, 'comments' => 'PRIVATE-RESPONSE-CONTENT']]);
    $this->actingAs($this->head);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
});

it('shows section completion separately from students with no assignments and supports irregular membership', function () {
    $summary = app(Progress::class)->sectionSummaries($this->head, $this->campus, [$this->section->id, $this->otherSection->id]);
    expect($summary[$this->section->id])->toMatchArray(['students' => 3, 'assigned_students' => 2, 'complete_students' => 1,
        'followup_students' => 1, 'unassigned_students' => 1, 'completed' => 1, 'total' => 2, 'remaining' => 1, 'completion_rate' => 50.0]);
    expect($summary[$this->otherSection->id])->toMatchArray(['students' => 1, 'assigned_students' => 1, 'complete_students' => 1, 'followup_students' => 0, 'completion_rate' => 100.0]);
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')->assertSet('viewMode', 'sections')
        ->assertCanSeeTableRecords([$this->section, $this->otherSection])->assertSee('50% complete')->assertSee('1 without assignments')
        ->assertDontSee('PRIVATE-RESPONSE-CONTENT');
});

it('opens the selected section students and keeps sections and semester filters visible', function () {
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')
        ->filterTable('section', $this->section->id)->assertCanSeeTableRecords([$this->section])->assertCanNotSeeTableRecords([$this->otherSection])
        ->callTableAction('students', $this->section)->assertSet('viewMode', 'students')
        ->assertCanSeeTableRecords([$this->student, $this->pending, $this->unassigned])->assertSee('No assignments')
        ->assertSee('BSIT 3A')->assertSee('0 out of 1')->assertTableFilterExists('section')
        ->filterTable('section', $this->otherSection->id)->assertCanSeeTableRecords([$this->student])->assertCanNotSeeTableRecords([$this->pending]);
});

it('does not call future closed exhausted or unauthorized evaluations open follow-ups', function () {
    $service = app(Progress::class);
    foreach ([['starts_at' => now()->addDay()], ['starts_at' => null, 'ends_at' => now()->subDay()], ['ends_at' => null, 'max_submissions' => 1], ['max_submissions' => null, 'evaluator_roles' => ['instructor']]] as $data) {
        $this->form->updateQuietly($data);
        $summary = $service->sectionSummaries($this->head, $this->campus, [$this->section->id])[$this->section->id];
        expect($summary['followup_students'])->toBe(0);
        $expected = $service->summary($this->student, $this->campus, $this->offering->semester_id, [$this->department->id]);
        $pending = $service->summary($this->pending, $this->campus, $this->offering->semester_id, [$this->department->id]);
        expect($summary['total'])->toBe($expected['total'] + $pending['total'])
            ->and($summary['completed'])->toBe($expected['completed'] + $pending['completed'])
            ->and($summary['remaining'])->toBe($expected['remaining'] + $pending['remaining']);
    }
});

it('does not treat a section with no evaluation assignments as zero completion or a follow-up', function () {
    $this->offering->update(['form_id' => null]);
    $summary = app(Progress::class)->sectionSummaries($this->head, $this->campus, [$this->section->id])[$this->section->id];
    expect($summary)->toMatchArray(['students' => 3, 'assigned_students' => 0, 'followup_students' => 0, 'completion_rate' => null]);
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')->assertSee('No evaluation assignments')->assertSee('No completion rate yet');
});

it('blocks other departments campuses semesters and forged section filters', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'name' => 'CCJ', 'code' => 'CCJ', 'is_active' => true]);
    $hidden = AcademicSection::create(['department_id' => $other->id, 'semester_id' => $this->offering->semester_id, 'name' => 'PRIVATE OTHER SECTION', 'is_active' => true]);
    $next = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31']);
    $past = AcademicSection::create(['department_id' => $this->department->id, 'semester_id' => $next->id, 'name' => 'OTHER PERIOD', 'is_active' => true]);
    $campus = Campus::create(['name' => 'Other campus', 'slug' => 'other-progress', 'is_active' => true]);
    $department = Department::create(['campus_id' => $campus->id, 'name' => 'Other', 'code' => 'OTHER', 'is_active' => true]);
    $year = SchoolYear::create(['campus_id' => $campus->id, 'name' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31']);
    $semester = Semester::create(['school_year_id' => $year->id, 'name' => 'First Semester', 'start_date' => '2026-06-01', 'end_date' => '2026-12-31']);
    $foreign = AcademicSection::create(['department_id' => $department->id, 'semester_id' => $semester->id, 'name' => 'PRIVATE FOREIGN', 'is_active' => true]);
    expect(app(Progress::class)->sectionSummaries($this->head, $this->campus, [$hidden->id, $foreign->id]))->toBe([]);
    Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')->assertCanSeeTableRecords([$this->section])
        ->assertCanNotSeeTableRecords([$hidden, $past, $foreign])->assertDontSee('PRIVATE OTHER SECTION')->assertDontSee('PRIVATE FOREIGN')
        ->filterTable('section', $hidden->id)->assertCanNotSeeTableRecords([$this->section])
        ->callAction('byStudent')->assertCanNotSeeTableRecords([$this->student, $this->pending]);
    $this->head->assignRole('super_admin');
    expect(array_keys(app(Progress::class)->sectionSummaries($this->head, $this->campus, [$hidden->id, $foreign->id])))->toBe([$hidden->id]);
});

it('refreshes completion after a student submits and clears the section filter when the period changes', function () {
    $component = Livewire::test(StudentEvaluationProgress::class)->callAction('bySection')->filterTable('section', $this->section->id)->assertSee('50% complete');
    FormSubmission::create(['course_offering_id' => $this->offering->id, 'form_id' => $this->form->id,
        'user_id' => $this->pending->id, 'data' => ['teaching_quality' => 5]]);
    $component->call('$refresh')->assertSee('100% complete');
    $next = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31']);
    $section = AcademicSection::create(['department_id' => $this->department->id, 'semester_id' => $next->id, 'name' => 'BSIT 3A', 'is_active' => true]);
    $component->filterTable('semester', $next->id)->assertSet('tableFilters.section.value', null)
        ->assertCanSeeTableRecords([$section])->assertCanNotSeeTableRecords([$this->section, $this->otherSection]);
});

it('loads section summaries in batches without one query set for every student', function () {
    $service = app(Progress::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $service->sectionSummaries($this->head, $this->campus, [$this->section->id]);
    $small = count(DB::getQueryLog());
    DB::disableQueryLog();
    foreach (User::factory()->count(100)->create(['is_student' => true, 'student_department_id' => $this->department->id]) as $student) {
        $student->assignRole('student');
        $student->campuses()->attach($this->campus);
        $this->offering->students()->attach($student);
        ($this->member)($student);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $summary = $service->sectionSummaries($this->head, $this->campus, [$this->section->id])[$this->section->id];
    $large = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($summary)->toMatchArray(['students' => 103, 'assigned_students' => 102, 'followup_students' => 101, 'completed' => 1, 'total' => 102])
        ->and($large)->toBeLessThanOrEqual($small + 5);
});

it('opens straight to the section view from a link and lists the sections furthest behind first', function () {
    Livewire::withQueryParams(['view' => 'sections'])->test(StudentEvaluationProgress::class)->assertSet('viewMode', 'sections')
        ->assertCanSeeTableRecords([$this->section, $this->otherSection], inOrder: true)
        ->assertTableFilterExists('section')->assertSee('Students not finished');
});
