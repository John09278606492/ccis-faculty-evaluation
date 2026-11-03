<?php

use App\Filament\Imports\InstructorImporter;
use App\Filament\Imports\StudentImporter;
use App\Filament\Imports\UserImporter;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * The spreadsheet imports an administrator uses to bring in a class list or a
 * list of instructors.
 */
beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ['offering' => $this->importOffering] = evaluationFixture();
    $this->importActor = $this->importOffering->instructor;
    $this->importActor->campuses()->attach($this->importOffering->campus);
    foreach (['create_user', 'update_user', 'import_academic_students', 'manage_academic_students'] as $permission) {
        $this->importActor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

});

function studentImporter(array $options = [], array $columnMap = []): StudentImporter
{
    $import = (new Import)->setRelation('user', test()->importActor);

    return new StudentImporter($import, $columnMap, array_merge(['campus_id' => test()->importOffering->campus->id, 'department_id' => test()->importOffering->course->department_id], $options));
}

function instructorImporter(array $options = [], array $columnMap = []): InstructorImporter
{
    $import = (new Import)->setRelation('user', test()->importActor);

    return new InstructorImporter($import, $columnMap, array_merge(['campus_id' => test()->importOffering->campus->id, 'department_id' => test()->importOffering->course->department_id], $options));
}

/**
 * The importer keeps the row and the record it is working on in protected
 * properties, so the test sets them the same way the import job does.
 */
function importerWithRow($importer, array $data, ?User $record = null): object
{
    foreach (User::all() as $existing) {
        $existing->campuses()->syncWithoutDetaching([test()->importOffering->campus->id]);
        if ($existing->student_id && ! $existing->is_instructor) {
            $existing->update(['is_student' => true, 'student_department_id' => test()->importOffering->course->department_id]);
        }
    }
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

it('rejects an incorrectly mapped employee column during an actual import', function () {
    $map = ['first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email', 'employee_id' => 'Student ID'];
    $importer = instructorImporter([], $map);
    $before = User::count();

    expect(fn () => $importer(['first_name' => 'Maria', 'last_name' => 'Cruz', 'email' => 'new.staff@example.test', 'Student ID' => 'E-IMPORT']))
        ->toThrow(ValidationException::class);
    expect(User::count())->toBe($before);
});

it('downloads the staff CSV template directly from Staff Accounts', function () {
    $this->importActor->givePermissionTo(Permission::findOrCreate('view_any_user', 'web'));
    Filament::setTenant($this->importOffering->campus, isQuiet: true);
    $this->actingAs($this->importActor);

    Livewire\Livewire::test(ListUsers::class)
        ->callAction('staffTemplate')
        ->assertFileDownloaded('instructors-template.csv');
});

it('does not update an existing student when the id belongs to someone else', function () {
    $offering = $this->importOffering;
    User::factory()->create(['student_id' => '2026-0001', 'email' => 'first@example.test']);

    $importer = importerWithRow(studentImporter(), [
        'email' => 'second@example.test',
        'student_id' => '2026-0001',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class);
});

it('updates a student that is imported again', function () {
    $offering = $this->importOffering;
    $existing = User::factory()->create(['student_id' => '2026-0001', 'email' => 'first@example.test']);

    $importer = importerWithRow(studentImporter(), [
        'email' => 'first@example.test',
        'student_id' => '2026-0001',
    ]);

    expect($importer->resolveRecord()->id)->toBe($existing->id);
});

it('refuses to import a student over an instructor', function () {
    $offering = $this->importOffering;
    User::factory()->create(['is_instructor' => true, 'email' => 'teacher@example.test']);

    $importer = importerWithRow(studentImporter(), [
        'email' => 'teacher@example.test',
        'student_id' => '2026-0002',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class);
});

it('refuses to import an instructor over a student', function () {
    $offering = $this->importOffering;
    User::factory()->create(['is_student' => true, 'email' => 'student@example.test']);

    $importer = importerWithRow(instructorImporter(), [
        'email' => 'student@example.test',
        'employee_id' => 'E-1',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class);
});

it('does not update an existing instructor when the employee id belongs to someone else', function () {
    $offering = $this->importOffering;
    User::factory()->create(['is_instructor' => true, 'employee_id' => 'E-1', 'email' => 'first@example.test']);

    $importer = importerWithRow(instructorImporter(), [
        'email' => 'second@example.test',
        'employee_id' => 'E-1',
    ]);

    expect(fn () => $importer->resolveRecord())
        ->toThrow(ValidationException::class);
});

it('marks an imported student as a student with a password nobody knows', function () {
    $offering = $this->importOffering;
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
    $offering = $this->importOffering;
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

it('gives an imported instructor a unique unknown initial password', function () {
    $offering = $this->importOffering;
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
        ->and($record->must_change_password)->toBeTrue()
        ->and(Hash::check('password123', $record->password))->toBeFalse();
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
