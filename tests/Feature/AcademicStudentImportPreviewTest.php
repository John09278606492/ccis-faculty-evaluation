<?php

use App\Filament\Resources\AcademicStudentResource\Pages\ListAcademicStudents;
use App\Models\Campus;
use App\Models\Semester;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\AcademicStudentImports;
use App\Services\AcademicStudents;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ['offering' => $this->offering] = evaluationFixture();
    $this->campus = $this->offering->campus;
    $this->actor = $this->offering->instructor;
    $this->actor->campuses()->attach($this->campus);
    foreach (['view_academic_students', 'manage_academic_students', 'import_academic_students'] as $permission) {
        $this->actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($this->actor);
    Filament::setTenant($this->campus);
    $this->semester = $this->offering->semester_id;
    $this->rows = [['student_id' => 'PREVIEW-001', 'first_name' => 'Maria', 'last_name' => 'Cruz', 'department_code' => $this->offering->course->department->code, 'sections' => 'BSIT 3A|BSIT 2B']];
    $this->imports = app(AcademicStudentImports::class);
});

it('reviews without student writes and commits once into the selected period', function () {
    $review = $this->imports->review($this->actor, $this->campus, $this->rows, $this->semester);
    expect($review['new_students'])->toBe(1)->and($review['error_count'])->toBe(0);
    expect(User::where('student_id', 'PREVIEW-001')->exists())->toBeFalse();
    expect($this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester))->toBe(1);
    $student = User::where('student_id', 'PREVIEW-001')->sole();
    expect(StudentSectionMembership::where('user_id', $student->id)->where('semester_id', $this->semester)->count())->toBe(2);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester))->toThrow(HttpException::class);
});

it('shows row errors and never partially imports an invalid plan', function () {
    $review = $this->imports->review($this->actor, $this->campus, [$this->rows[0], [...$this->rows[0], 'student_id' => 'PREVIEW-002', 'department_code' => 'FORBIDDEN']], $this->semester);
    expect($review['error_count'])->toBe(1)->and($review['errors'][0]['row'])->toBe(3);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester))->toThrow(ValidationException::class);
    expect(User::whereIn('student_id', ['PREVIEW-001', 'PREVIEW-002'])->count())->toBe(0);
});

it('rechecks permission department and period instead of trusting the reviewed token', function () {
    $review = $this->imports->review($this->actor, $this->campus, $this->rows, $this->semester);
    $otherSemester = Semester::create(['school_year_id' => $this->offering->semester->school_year_id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => false]);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $otherSemester->id))->toThrow(HttpException::class);
    $this->actor->revokePermissionTo('import_academic_students');
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester))->toThrow(HttpException::class);
    $this->actor->givePermissionTo('import_academic_students');
    $this->actor->update(['instructor_department_id' => null]);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester))->toThrow(ValidationException::class);
    expect(User::where('student_id', 'PREVIEW-001')->exists())->toBeFalse();
});

it('does not let another user or campus reuse a review token', function () {
    $review = $this->imports->review($this->actor, $this->campus, $this->rows, $this->semester);
    $other = User::factory()->create(['is_student' => false, 'instructor_department_id' => $this->actor->instructor_department_id]);
    $other->campuses()->attach($this->campus);
    $other->givePermissionTo(['view_academic_students', 'manage_academic_students', 'import_academic_students']);
    expect(fn () => $this->imports->getReview($other, $this->campus, $review['token'], $this->semester))->toThrow(HttpException::class);
    $foreign = Campus::create(['name' => 'Foreign', 'slug' => 'foreign', 'is_active' => true]);
    expect(fn () => $this->imports->getReview($this->actor, $foreign, $review['token'], $this->semester))->toThrow(HttpException::class);
});

it('requires another review when an existing record changes', function () {
    $student = app(AcademicStudents::class)->save($this->actor, $this->campus, [...$this->rows[0], 'student_department_id' => $this->actor->instructor_department_id, 'semester_id' => $this->semester]);
    $review = $this->imports->review($this->actor, $this->campus, $this->rows, $this->semester);
    expect($review['updated_students'])->toBe(1);
    $student->update(['first_name' => 'Changed']);
    expect(fn () => $this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester))->toThrow(ValidationException::class);
    expect($student->fresh()->first_name)->toBe('Changed');
});

it('deduplicates irregular memberships and validates repeated-ID conflicts during review', function () {
    $rows = [$this->rows[0], [...$this->rows[0], 'sections' => 'BSIT 1C']];
    $review = $this->imports->review($this->actor, $this->campus, $rows, $this->semester);
    expect($review['new_students'])->toBe(1)->and($review['duplicate_rows'])->toBe(1)->and($review['error_count'])->toBe(0);
    $this->imports->commit($this->actor, $this->campus, $review['token'], $this->semester);
    expect(User::where('student_id', 'PREVIEW-001')->count())->toBe(1);
    $bad = $this->imports->review($this->actor, $this->campus, [$this->rows[0], [...$this->rows[0], 'last_name' => 'Different']], $this->semester);
    expect($bad['error_count'])->toBe(1);
});

it('retains parsed rows after temporary upload deletion and requires the review before UI commit', function () {
    Storage::fake('local');
    Storage::disk('local')->put('academic-imports/review.csv', "student_id,first_name,last_name,department_code,sections\nPREVIEW-001,Maria,Cruz,{$this->rows[0]['department_code']},BSIT 3A|BSIT 2B\n");
    Livewire::test(ListAcademicStudents::class)->callAction('importStudents', data: ['semester_id' => $this->semester, 'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('students.csv', Storage::disk('local')->get('academic-imports/review.csv'))])->assertHasNoActionErrors();
    $review = finishBackgroundReview();
    expect($review->payload['review_plan']['rows'])->not->toBeEmpty();
    Storage::disk('local')->assertMissing($review->payload['source']);
    expect(User::where('student_id', 'PREVIEW-001')->exists())->toBeFalse();
    confirmBackgroundReview($review);
    expect(User::where('student_id', 'PREVIEW-001')->exists())->toBeTrue();
});
