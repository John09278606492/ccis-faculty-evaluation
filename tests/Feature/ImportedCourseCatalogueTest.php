<?php

use App\Filament\Pages\BackgroundProcessing;
use App\Filament\Resources\AcademicStudentResource\Pages\ListAcademicStudents;
use App\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Models\Campus;
use App\Models\Course;
use App\Models\Department;
use App\Models\FormSubmission;
use App\Models\StudentSubjectEnrolment;
use App\Models\User;
use App\Services\AcademicStudentImports;
use App\Services\AcademicStudents;
use App\Services\ImportedCourseCatalogue;
use App\Services\StudentAcademicRecords;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
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
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students', 'create_course', 'manage_evaluators_course::offering', 'update_course::offering'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->campus, isQuiet: true);
    $this->catalogue = app(ImportedCourseCatalogue::class);
    $this->imports = app(AcademicStudentImports::class);
    $this->row = ['student_id' => 'CAT-001', 'first_name' => 'Maria', 'last_name' => 'Sample', 'department_code' => $this->department->code,
        'program' => 'BSIT', 'subjects' => [['subject_code' => 'CS101', 'description' => 'Introduction to Computing', 'units' => 3], ['subject_code' => 'NEW 101', 'description' => 'New Subject', 'units' => 3]]];
});

it('adds missing courses during confirmed student import and reuses existing normalized codes', function () {
    $existing = $this->offering->course->fresh()->getRawOriginal();
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review)->toMatchArray(['new_courses' => 1, 'existing_courses' => 1, 'courses_needing_review' => 0]);
    expect(Course::where('code', 'NEW 101')->exists())->toBeFalse();
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(Course::where('department_id', $this->department->id)->count())->toBe(2)
        ->and(Course::where('code', 'NEW 101')->sole()->name)->toBe('New Subject')
        ->and($this->offering->course->fresh()->getRawOriginal())->toBe($existing)
        ->and(StudentSubjectEnrolment::count())->toBe(2);
    $row = $this->row;
    $row['student_id'] = 'CAT-002';
    $row['subjects'][0]['subject_code'] = 'cs 101';
    $row['subjects'][1]['subject_code'] = 'new101';
    $review = $this->imports->review($this->actor, $this->campus, [$row], $this->offering->semester_id);
    expect($review)->toMatchArray(['new_courses' => 0, 'existing_courses' => 2]);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(Course::count())->toBe(2)->and(User::whereIn('student_id', ['CAT-001', 'CAT-002'])->count())->toBe(2);
});

it('reads many repeated CSV subject blocks without treating degree programs as subjects', function () {
    $head = ['student_id', 'first_name', 'last_name', 'department_code', 'program', 'total_units'];
    $values = ['CSV-CAT-001', 'Ana', 'Sample', $this->department->code, 'BSCS', 24];
    for ($i = 1; $i <= 8; $i++) {
        $head = [...$head, 'Subject Code', 'Description', 'Lec', 'Lab', 'Units'];
        $values = [...$values, 'SUB'.$i, 'Subject '.$i, 3, 0, 3];
    }
    $stream = fopen('php://temp', 'r+');
    fputcsv($stream, $head);
    fputcsv($stream, $values);
    rewind($stream);
    $file = UploadedFile::fake()->createWithContent('campus.csv', stream_get_contents($stream));
    fclose($stream);
    $review = $this->imports->reviewUpload($this->actor, $this->campus, $file, $this->offering->semester_id);
    expect($review)->toMatchArray(['new_courses' => 8, 'subject_enrolments' => 8, 'error_count' => 0]);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(Course::where('code', 'like', 'SUB%')->count())->toBe(8)->and(Course::where('code', 'BSCS')->exists())->toBeFalse();
});

it('adds a different title under an existing code as its own course and keeps unit conflicts unchanged', function () {
    $row = $this->row;
    $row['student_id'] = 'CAT-002';
    $row['subjects'][0]['description'] = 'Different existing title';
    $row['subjects'][1]['description'] = 'Different new title';
    $review = $this->imports->review($this->actor, $this->campus, [$this->row, $row], $this->offering->semester_id);
    expect($review)->toMatchArray(['new_courses' => 3, 'courses_needing_review' => 0, 'error_count' => 0]);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(Course::count())->toBe(4)->and($this->offering->course->fresh()->name)->toBe('Introduction To Computing')
        ->and(StudentSubjectEnrolment::count())->toBe(4);
    $row = $this->row;
    $row['subjects'] = [['subject_code' => 'CS101', 'description' => 'Introduction to Computing', 'units' => 4]];
    expect($this->catalogue->review($this->actor, $this->campus, [$row])['courses_needing_review'])->toBe(1);
});

it('does not reactivate courses or silently truncate unsupported values', function () {
    $this->offering->course->update(['is_active' => false]);
    $row = $this->row;
    $row['subjects'] = [$row['subjects'][0], ['subject_code' => str_repeat('X', 21), 'description' => 'Long code', 'units' => 3],
        ['subject_code' => 'LONGTITLE', 'description' => str_repeat('X', 256), 'units' => 3],
        ['subject_code' => 'MAXUNITS', 'description' => 'Too many units', 'units' => 100],
        ['subject_code' => 'DECIMAL', 'description' => 'Fractional units', 'units' => 1.25]];
    $review = $this->catalogue->synchronize($this->actor, $this->campus, [$row]);
    expect($review)->toMatchArray(['new_courses' => 0, 'courses_needing_review' => 5])
        ->and(Course::count())->toBe(1)->and($this->offering->course->fresh()->is_active)->toBeFalse();
});

it('retains import access without granting course creation to an unauthorized importer', function () {
    $this->actor->revokePermissionTo('create_course');
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    expect($review)->toMatchArray(['new_courses' => 0, 'existing_courses' => 1, 'courses_needing_review' => 1]);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id);
    expect(StudentSubjectEnrolment::count())->toBe(2)->and(Course::count())->toBe(1);
    expect(fn () => $this->catalogue->previewStored($this->actor, $this->campus, $this->offering->semester_id, [$this->department->code]))->toThrow(HttpException::class);
    Livewire::test(ListAcademicStudents::class)->assertActionHidden('syncImportedCourses');
});

it('invalidates stale catalogue previews and never creates courses from an invalid student import', function () {
    $review = $this->imports->review($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    $this->offering->course->update(['units' => 4]);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(User::where('student_id', $this->row['student_id'])->exists())->toBeFalse()->and(Course::count())->toBe(1);
    $bad = $this->row;
    $bad['first_name'] = '';
    $review = $this->imports->review($this->actor, $this->campus, [$bad], $this->offering->semester_id);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->offering->semester_id))->toThrow(ValidationException::class);
    expect(Course::where('code', 'NEW 101')->exists())->toBeFalse();
});

it('synchronizes already imported subjects through the review wizard without replacing rosters or results', function () {
    $this->actor->revokePermissionTo('create_course');
    app(AcademicStudents::class)->import($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    $this->actor->givePermissionTo('create_course');
    $originalRoster = $this->offering->students()->pluck('users.id')->all();
    $response = FormSubmission::create(['course_offering_id' => $this->offering->id, 'form_id' => $this->offering->form_id, 'data' => ['teaching_quality' => 5]]);
    Livewire::test(ListAcademicStudents::class)->callAction('syncImportedCourses', data: ['semester_id' => $this->offering->semester_id, 'department_codes' => [$this->department->code]])->assertHasNoActionErrors();
    expect(Course::count())->toBe(1);
    $reviewTask = finishBackgroundReview();
    Livewire::test(BackgroundProcessing::class)->mountTableAction('view', $reviewTask)->assertSee('New Subject');
    confirmBackgroundReview($reviewTask);
    expect(Course::count())->toBe(2)->and($this->offering->students()->pluck('users.id')->all())->toBe($originalRoster)
        ->and($response->fresh()->data)->toBe(['teaching_quality' => 5]);
    $review = $this->catalogue->previewStored($this->actor, $this->campus, $this->offering->semester_id, [$this->department->code]);
    expect($this->catalogue->commitStored($this->actor, $this->campus, $review['token'])['new_courses'])->toBe(0);
    expect(fn () => $this->catalogue->commitStored($this->actor, $this->campus, $review['token']))->toThrow(HttpException::class);
});

it('restricts catalogue sync to authorized campuses and departments and rechecks changed permissions', function () {
    $other = Department::create(['campus_id' => $this->campus->id, 'code' => 'OTHER', 'name' => 'Other College', 'is_active' => true]);
    expect(fn () => $this->catalogue->previewStored($this->actor, $this->campus, $this->offering->semester_id, ['OTHER']))->toThrow(HttpException::class);
    $foreign = Campus::create(['name' => 'Foreign', 'slug' => 'foreign-catalogue', 'is_active' => true]);
    expect(fn () => $this->catalogue->previewStored($this->actor, $foreign, $this->offering->semester_id, []))->toThrow(HttpException::class);
    $this->actor->revokePermissionTo('create_course');
    app(AcademicStudents::class)->import($this->actor, $this->campus, [$this->row], $this->offering->semester_id);
    $this->actor->givePermissionTo('create_course');
    $review = $this->catalogue->previewStored($this->actor, $this->campus, $this->offering->semester_id, [$this->department->code]);
    $this->actor->revokePermissionTo('create_course');
    expect(fn () => $this->catalogue->commitStored($this->actor, $this->campus, $review['token']))->toThrow(HttpException::class);
    expect(Course::count())->toBe(1);
});

it('offers enrolled candidates for equivalent subject-code spacing without attaching them automatically', function () {
    $row = $this->row;
    $row['subjects'][0]['subject_code'] = 'CS 101';
    app(AcademicStudents::class)->import($this->actor, $this->campus, [$row], $this->offering->semester_id);
    $second = $this->row;
    $second['student_id'] = 'CAT-002';
    app(AcademicStudents::class)->import($this->actor, $this->campus, [$second], $this->offering->semester_id);
    $key = StudentAcademicRecords::subjectKey('CS 101', 'Introduction to Computing');
    $service = app(StudentAcademicRecords::class);
    expect($service->subjectOptions($this->actor, $this->offering))->toHaveKey($key)->toHaveCount(1)
        ->and($service->preferredSubject($this->actor, $this->offering))->toBe($key)
        ->and($service->candidates($this->actor, $this->offering, $key)->pluck('student_id')->all())->toBe(['CAT-001', 'CAT-002'])
        ->and($this->offering->students()->where('student_id', 'CAT-001')->exists())->toBeFalse();
    $service->addEvaluators($this->actor, $this->offering, $key);
    expect($this->offering->students()->where('student_id', 'CAT-001')->exists())->toBeTrue();
});

it('allows the same course code in distinct departments while rejecting a duplicate in the selected department', function () {
    $this->actor->assignRole('super_admin');
    foreach (['view_any_course', 'view_course', 'update_course'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $department = Department::create(['campus_id' => $this->campus->id, 'name' => 'Another College', 'code' => 'ANOTHER', 'is_active' => true]);
    $data = ['department_id' => $department->id, 'code' => 'CS101', 'name' => 'Another Curriculum Subject', 'units' => 3, 'is_active' => true];
    Livewire::test(CreateCourse::class)->assertSuccessful()->fillForm($data)->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateCourse::class)->assertSuccessful()->fillForm($data)->call('create')->assertHasFormErrors(['code' => 'unique']);
    expect(Course::where('code', 'CS101')->count())->toBe(2);
});
