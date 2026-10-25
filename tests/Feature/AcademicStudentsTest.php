<?php

use App\Filament\Resources\AcademicStudentResource\Pages\CreateAcademicStudent;
use App\Filament\Resources\AcademicStudentResource\Pages\ListAcademicStudents;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Models\Campus;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\User;
use App\Services\AcademicStudents;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->department = $this->offering->course->department;
    $this->actor = $this->offering->instructor;
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'view_any_user', 'create_user', 'update_user', 'update_course::offering', 'manage_evaluators_course::offering', 'view_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setTenant($this->campus);
    $this->service = app(AcademicStudents::class);
    $this->profile = ['student_id' => 'BAL-0001', 'first_name' => 'Maria', 'last_name' => 'Cruz', 'student_department_id' => $this->department->id, 'sections' => ['BSIT 3A']];
});

it('adds academic student profiles with campus membership and student access', function () {
    Livewire::test(CreateAcademicStudent::class)->fillForm($this->profile)->call('create')->assertHasNoFormErrors();
    $student = User::where('student_id', 'BAL-0001')->sole();
    expect($student->student_department_id)->toBe($this->department->id)->and($student->sections)->toBe(['BSIT 3A'])
        ->and($student->hasRole('student'))->toBeTrue()->and($student->campuses()->whereKey($this->campus->id)->exists())->toBeTrue();
    Livewire::test(ListAcademicStudents::class)->assertSuccessful()->assertCanSeeTableRecords([$student]);
});

it('imports mixed sections atomically and refuses accounts outside the assigned campus', function () {
    $rows = [array_merge($this->profile, ['department_code' => $this->department->code]),
        array_merge($this->profile, ['student_id' => 'BAL-0002', 'sections' => ['BSIT 3B'], 'department_code' => $this->department->code])];
    expect($this->service->import($this->actor, $this->campus, $rows))->toBe(2);
    expect($this->service->import($this->actor, $this->campus, $rows))->toBe(2);
    expect(User::whereIn('student_id', ['BAL-0001', 'BAL-0002'])->count())->toBe(2);
    $badRows = [array_merge($rows[0], ['student_id' => 'NEW-0003']), array_merge($rows[1], ['student_id' => 'NEW-0004', 'department_code' => 'UNAUTHORIZED'])];
    expect(fn () => $this->service->import($this->actor, $this->campus, $badRows))->toThrow(ValidationException::class);
    expect(User::where('student_id', 'NEW-0003')->exists())->toBeFalse();
    $otherCampus = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    $foreign = User::factory()->create(['is_student' => true, 'student_id' => 'FOREIGN-001']);
    $foreign->campuses()->attach($otherCampus);
    expect(fn () => $this->service->import($this->actor, $this->campus, [array_merge($rows[0], ['student_id' => $foreign->student_id])]))->toThrow(ValidationException::class);
    expect($foreign->campuses()->whereKey($this->campus->id)->exists())->toBeFalse();
});

it('adds multiple sections with individual inclusions and exclusions without duplicating or deleting responses', function () {
    $a = $this->service->save($this->actor, $this->campus, $this->profile);
    $b = $this->service->save($this->actor, $this->campus, array_merge($this->profile, ['student_id' => 'BAL-0002', 'sections' => ['BSIT 3B']]));
    $extra = $this->service->save($this->actor, $this->campus, array_merge($this->profile, ['student_id' => 'BAL-0003', 'sections' => ['BSIT 3C']]));
    $groups = array_keys($this->service->sectionOptions($this->actor, $this->campus));
    $selected = array_values(array_filter($groups, fn ($key) => ! str_ends_with($key, '3C')));
    $this->offering->students()->detach();
    $this->offering->students()->attach($b);
    $response = FormSubmission::create(['form_id' => $this->offering->form_id, 'course_offering_id' => $this->offering->id, 'user_id' => $b->id, 'data' => ['teaching_quality' => 5]]);
    expect($this->service->assign($this->actor, $this->offering, $selected, [$extra->id], [$b->id]))->toBe(2);
    $this->service->assign($this->actor, $this->offering, $selected, [$extra->id], [$b->id]);
    expect($this->offering->students()->pluck('users.id')->sort()->values()->all())->toBe(collect([$a->id, $extra->id])->sort()->values()->all());
    expect($response->fresh())->not->toBeNull();
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $this->offering, 'pageClass' => EditCourseOffering::class])->assertTableActionVisible('assignSections');
});

it('rejects forged departments and section selections even with account permissions', function () {
    $other = Department::create(['name' => 'Other', 'code' => 'OTHER', 'campus_id' => $this->campus->id, 'is_active' => true]);
    expect(fn () => $this->service->save($this->actor, $this->campus, array_merge($this->profile, ['student_department_id' => $other->id])))->toThrow(ValidationException::class);
    expect(fn () => $this->service->assign($this->actor, $this->offering, ['forged-group']))->toThrow(HttpException::class);
});

it('provides the example download and processes the CSV upload action', function () {
    Storage::fake('local');
    $csv = "student_id,first_name,middle_name,last_name,email,department_code,sections\nUPLOAD-001,Juan,,Cruz,,{$this->department->code},BSIT 3A|BSIT 2B\n";
    Storage::disk('local')->put('academic-imports/test.csv', $csv);
    Livewire::test(ListAcademicStudents::class)->callAction('exampleCsv')->assertFileDownloaded('academic-students-example.csv');
    Livewire::test(ListAcademicStudents::class)->callAction('importStudents', data: ['semester_id' => $this->offering->semester_id, 'file' => UploadedFile::fake()->createWithContent('students.csv', $csv)])->assertHasNoActionErrors();
    expect(User::where('student_id', 'UPLOAD-001')->exists())->toBeFalse();
    $review = finishBackgroundReview();
    confirmBackgroundReview($review);
    expect(User::where('student_id', 'UPLOAD-001')->sole()->sections)->toBe(['BSIT 3A', 'BSIT 2B']);
});

it('supports an irregular student in several sections and assigns the student only once', function () {
    $student = $this->service->save($this->actor, $this->campus, [...$this->profile, 'sections' => ['bsit 3a', 'BSIT 2B', 'BSIT 3A']]);
    expect($student->sections)->toBe(['BSIT 3A', 'BSIT 2B']);
    $options = $this->service->sectionOptions($this->actor, $this->campus);
    expect($options)->toHaveCount(2);
    $this->offering->students()->detach();
    expect($this->service->assign($this->actor, $this->offering, array_keys($options)))->toBe(1);
    expect($this->offering->students()->count())->toBe(1);
    foreach (['BSIT 3A', 'BSIT 2B'] as $section) {
        Livewire::test(ListAcademicStudents::class)->filterTable('section', $section)->assertCanSeeTableRecords([$student]);
    }
    $student = $this->service->save($this->actor, $this->campus, [...$this->profile, 'sections' => ['BSIT 2B']], $student);
    expect($student->sections)->toBe(['BSIT 2B']);
});

it('merges repeated imported student IDs into multiple section memberships', function () {
    $rows = [
        [...$this->profile, 'sections' => 'BSIT 3A', 'department_code' => $this->department->code],
        [...$this->profile, 'sections' => 'BSIT 2B|BSIT 3A', 'department_code' => $this->department->code],
    ];
    $this->service->import($this->actor, $this->campus, $rows);
    expect(User::where('student_id', 'BAL-0001')->count())->toBe(1);
    expect(User::where('student_id', 'BAL-0001')->sole()->sections)->toBe(['BSIT 3A', 'BSIT 2B']);
});
