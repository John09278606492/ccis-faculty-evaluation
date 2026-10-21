<?php

use App\Filament\Imports\InstructorImporter;
use App\Filament\Imports\StudentImporter;
use App\Filament\Imports\UserImporter;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;

/**
 * The spreadsheet imports an administrator uses to bring in a class list or a
 * list of instructors.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function studentImporter(array $options = [], array $columnMap = []): StudentImporter
{
    return new StudentImporter(new Import, $columnMap, $options);
}

function instructorImporter(array $options = [], array $columnMap = []): InstructorImporter
{
    return new InstructorImporter(new Import, $columnMap, $options);
}

/**
 * The importer keeps the row and the record it is working on in protected
 * properties, so the test sets them the same way the import job does.
 */
function importerWithRow($importer, array $data, ?User $record = null): object
{
    (new ReflectionProperty($importer, 'data'))->setValue($importer, $data);
    if ($record !== null) {
        (new ReflectionProperty($importer, 'record'))->setValue($importer, $record);
    }

    return $importer;
}

/**
 * The importer prepares the record in a protected hook, so the test calls it
 * the same way the import job does.
 */
function callImporterHook($importer, string $hook): void
{
    (new ReflectionMethod($importer, $hook))->invoke($importer);
}

/**
 * The record the importer is working on, which it keeps protected.
 */
function importerRecord($importer): User
{
    return (new ReflectionProperty($importer, 'record'))->getValue($importer);
}

it('refuses a student id column it does not recognise', function () {
    $importer = studentImporter([], ['student_id' => 'Employee Number']);

    expect($importer->getValidationRules())->toHaveKey('_column_check');
});

it('accepts the usual spellings of a student id column', function () {
    foreach (['student_id', 'Student ID', 'stud_id', 'id_number'] as $header) {
        expect(studentImporter([], ['student_id' => $header])->getValidationRules())
            ->not->toHaveKey('_column_check');
    }
});

it('refuses an employee id column it does not recognise', function () {
    $importer = instructorImporter([], ['employee_id' => 'Student ID']);

    expect($importer->getValidationRules())->toHaveKey('_column_check');
});

it('accepts the usual spellings of an employee id column', function () {
    foreach (['employee_id', 'Employee ID', 'emp_id', 'staff_id'] as $header) {
        expect(instructorImporter([], ['employee_id' => $header])->getValidationRules())
            ->not->toHaveKey('_column_check');
    }
});

it('does not update an existing student when the id belongs to someone else', function () {
    ['offering' => $offering] = evaluationFixture();
    User::factory()->create(['student_id' => '2026-0001', 'email' => 'first@example.test']);

    $importer = importerWithRow(studentImporter(), [
        'email' => 'second@example.test',
        'student_id' => '2026-0001',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class, 'Student ID 2026-0001 is already assigned to first@example.test.');
});

it('updates a student that is imported again', function () {
    ['offering' => $offering] = evaluationFixture();
    $existing = User::factory()->create(['student_id' => '2026-0001', 'email' => 'first@example.test']);

    $importer = importerWithRow(studentImporter(), [
        'email' => 'first@example.test',
        'student_id' => '2026-0001',
    ]);

    expect($importer->resolveRecord()->id)->toBe($existing->id);
});

it('refuses to import a student over an instructor', function () {
    ['offering' => $offering] = evaluationFixture();
    User::factory()->create(['is_instructor' => true, 'email' => 'teacher@example.test']);

    $importer = importerWithRow(studentImporter(), [
        'email' => 'teacher@example.test',
        'student_id' => '2026-0002',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class, 'Email teacher@example.test is already registered as an instructor.');
});

it('refuses to import an instructor over a student', function () {
    ['offering' => $offering] = evaluationFixture();
    User::factory()->create(['is_student' => true, 'email' => 'student@example.test']);

    $importer = importerWithRow(instructorImporter(), [
        'email' => 'student@example.test',
        'employee_id' => 'E-1',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class, 'Email student@example.test is already registered as a student.');
});

it('does not update an existing instructor when the employee id belongs to someone else', function () {
    ['offering' => $offering] = evaluationFixture();
    User::factory()->create(['is_instructor' => true, 'employee_id' => 'E-1', 'email' => 'first@example.test']);

    $importer = importerWithRow(instructorImporter(), [
        'email' => 'second@example.test',
        'employee_id' => 'E-1',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class, 'Employee ID E-1 is already assigned to first@example.test.');
});

it('marks an imported student as a student with a password nobody knows', function () {
    ['offering' => $offering] = evaluationFixture();
    $importer = importerWithRow(studentImporter(), [
        'email' => 'new@example.test',
        'student_id' => '2026-0003',
    ], new User);

    callImporterHook($importer, 'beforeSave');
    $record = importerRecord($importer);

    expect($record->is_student)->toBeTrue()
        ->and($record->is_instructor)->toBeFalse()
        ->and($record->password)->not->toBeNull();
});

it('saves an imported student without asking them to change a password', function () {
    ['offering' => $offering] = evaluationFixture();
    $importer = importerWithRow(studentImporter(), [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'email' => 'new@example.test',
        'student_id' => '2026-0003',
    ], new User(['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'email' => 'new@example.test', 'student_id' => '2026-0003']));

    callImporterHook($importer, 'beforeSave');
    $record = importerRecord($importer);
    $record->save();

    // A student signs in with a PIN from the class list, never a shared password.
    expect($record->fresh()->must_change_password)->toBeFalse()
        ->and($record->fresh()->is_student)->toBeTrue();
});

it('gives an imported instructor the shared starting password', function () {
    ['offering' => $offering] = evaluationFixture();
    $importer = importerWithRow(instructorImporter(['department_id' => $offering->course->department_id]), [
        'email' => 'new@example.test',
        'employee_id' => 'E-1',
    ], new User);

    callImporterHook($importer, 'beforeSave');
    $record = importerRecord($importer);

    expect($record->is_instructor)->toBeTrue()
        ->and($record->is_student)->toBeFalse()
        ->and($record->instructor_department_id)->toBe($offering->course->department_id)
        // The instructor must choose their own password at first sign-in.
        ->and($record->must_change_password)->toBeTrue();
});

it('refuses a student import without a student id', function () {
    $importer = new UserImporter(new Import, [], ['profile_type' => 'student']);
    (new ReflectionProperty($importer, 'data'))->setValue($importer, ['email' => 'new@example.test']);

    expect(fn () => $importer->beforeValidate())
        ->toThrow(Exception::class, 'Student ID is required for student imports.');
});

it('refuses an instructor import without an employee id', function () {
    $importer = new UserImporter(new Import, [], ['profile_type' => 'instructor']);
    (new ReflectionProperty($importer, 'data'))->setValue($importer, ['email' => 'new@example.test']);

    expect(fn () => $importer->beforeValidate())
        ->toThrow(Exception::class, 'Employee ID is required for instructor imports.');
});
