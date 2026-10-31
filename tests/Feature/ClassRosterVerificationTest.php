<?php

use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Filament\Resources\TeachingClassResource\Pages\EditTeachingClass;
use App\Models\FormSubmission;
use App\Models\StudentAcademicRecord;
use App\Models\User;
use App\Services\AcademicSections;
use App\Services\ClassRosterVerification;
use App\Services\EvaluationSections;
use App\Services\EvaluationSubmissionIdentity;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    ['student' => $this->listed, 'offering' => $this->class, 'form' => $this->form] = evaluationFixture(false);
    $this->actor = $this->class->instructor;
    $this->campus = $this->class->campus;
    $this->department = $this->class->course->department;
    $this->actor->campuses()->attach($this->campus);
    $role = Role::findOrCreate('section_coordinator', 'web');
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'manage_evaluators_course::offering', 'update_course::offering', 'view_course::offering'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actor->assignRole($role);
    $make = function (string $id) {
        $student = $id === '000123' ? $this->listed : User::factory()->create(['is_student' => true]);
        $student->update(['student_id' => $id, 'student_department_id' => $this->department->id]);
        $student->assignRole('student');
        $student->campuses()->syncWithoutDetaching($this->campus);
        StudentAcademicRecord::create(['user_id' => $student->id, 'semester_id' => $this->class->semester_id, 'program' => 'BSIT']);

        return $student;
    };
    $this->listed = $make('000123');
    $this->kept = $make('000124');
    $this->extra = $make('000125');
    $this->submittedExtra = $make('000126');
    $this->class->students()->attach([$this->kept->id, $this->extra->id, $this->submittedExtra->id], ['enrolled_at' => now()]);
    FormSubmission::create(['form_id' => $this->form->id, 'course_offering_id' => $this->class->id, 'data' => ['teaching_quality' => 5], 'submitted_at' => now(),
        'submission_fingerprint' => app(EvaluationSubmissionIdentity::class)->forUser($this->class, $this->submittedExtra->id)]);
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    Storage::fake('local');
    $this->section = app(AcademicSections::class)->save($this->actor, $this->campus, ['name' => 'BSIT 3A', 'department_id' => $this->department->id, 'semester_id' => $this->class->semester_id, 'is_active' => true]);
    $this->files = [['name' => '3a.csv', 'title' => 'BSIT 3A', 'ids' => ['000123', '000124', '999999']]];
});

it('compares a section list with the class evaluators without changing anything', function () {
    $result = app(ClassRosterVerification::class)->compare($this->actor, $this->class, $this->files);

    expect($result['verified'])->toBe(1)->and($result['current'])->toBe(3)->and($result['listed'])->toBe(3)
        ->and(array_column($result['missing'], 'id'))->toBe(['000123'])
        ->and(array_column($result['extra'], 'id'))->toBe(['000125', '000126'])
        ->and(array_column($result['extra'], 'submitted'))->toBe([false, true])
        ->and($result['unknown'])->toBe(['999999'])
        ->and(array_column($result['pending'], 'id'))->toBe(['000124'])
        ->and($result['files'][0]['section'])->toBe('BSIT 3A')
        ->and($this->class->students()->count())->toBe(3);
});

it('adds missing students, removes unlisted ones except those who submitted, and fills report sections', function () {
    $result = app(ClassRosterVerification::class)->apply($this->actor, $this->class, $this->files, true, true, true);

    expect($result)->toBe(['added' => 1, 'removed' => 1, 'kept' => 1, 'sections' => 2]);
    $class = $this->class->fresh();
    expect($class->students()->pluck('student_id')->sort()->values()->all())->toBe(['000123', '000124', '000126'])
        ->and(app(EvaluationSections::class)->forStudent($class, $this->listed))->toBe('BSIT 3A')
        ->and(app(EvaluationSections::class)->forStudent($class, $this->kept))->toBe('BSIT 3A')
        ->and($class->evaluator_selection['auto_section_roster'])->toBeFalse();
});

it('verifies a class from the Evaluators tab with an uploaded list', function () {
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->class, 'pageClass' => EditTeachingClass::class])
        ->assertTableActionVisible('verifyWithList')
        ->mountTableAction('verifyWithList')
        ->setTableActionData(['files' => [UploadedFile::fake()->createWithContent('3a.csv', "BS IT - 3A\nID No.\n000123\n000124\n")]])
        ->assertSee('In the list but not evaluators of this class: 1')->assertSee('Evaluators not in any list: 2')
        ->setTableActionData(['add_missing' => true, 'remove_extra' => false, 'fill_sections' => false])
        ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNotified('Evaluators checked against the lists');

    expect($this->class->students()->count())->toBe(4);
});
