<?php

use App\Filament\Pages\MyAdvising;
use App\Filament\Resources\AdvisoryClassResource;
use App\Filament\Resources\AdvisoryClassResource\Pages\ManageAdvisoryClasses;
use App\Filament\Resources\AdvisoryTaskResource;
use App\Filament\Resources\AdvisoryTaskResource\Pages\CreateAdvisoryTask;
use App\Filament\Resources\AdvisoryTaskResource\Pages\EditAdvisoryTask;
use App\Filament\Resources\AdvisoryTaskResource\Pages\ManageSubmissions;
use App\Filament\Resources\AdvisoryTaskResource\Pages\ReviewSubmission;
use App\Models\AcademicSection;
use App\Models\AdvisoryClass;
use App\Models\AdvisoryTask;
use App\Models\Campus;
use App\Models\SchoolYear;
use App\Models\Semester;
use App\Models\StudentSectionMembership;
use App\Models\User;
use App\Services\Advising;
use App\Services\StudentTodo;
use App\Services\UserAccessAdministration;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function advisingFixture(): array
{
    extract(evaluationFixture());
    $instructor = $offering->instructor;
    $campus = $offering->campus;
    $instructor->campuses()->attach($campus);
    foreach (['class', 'task'] as $type) {
        foreach (['view_any', 'view', 'create', 'update', 'delete'] as $prefix) {
            $instructor->givePermissionTo(Permission::findOrCreate("{$prefix}_advisory::{$type}", 'web'));
        }
    }
    $student->givePermissionTo(Permission::findOrCreate('page_MyAdvising', 'web'));
    $membership = StudentSectionMembership::create(['user_id' => $student->id, 'department_id' => $offering->course->department_id, 'semester_id' => $offering->semester_id, 'section' => 'BSIT 3A']);
    $class = AdvisoryClass::create(['academic_section_id' => $membership->academic_section_id, 'adviser_id' => $instructor->id, 'created_by' => $instructor->id]);
    $task = AdvisoryTask::create(['advisory_class_id' => $class->id, 'advising_semester_id' => $membership->semester_id, 'title' => 'Enrollment advising', 'requirements' => ['Grades screenshot', 'Advising form PDF'], 'due_at' => now()->addDay(), 'consequence' => AdvisoryTask::DEFAULT_CONSEQUENCE, 'status' => 'open']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($campus, isQuiet: true);
    Storage::fake(Advising::DISK);

    return compact('student', 'instructor', 'campus', 'membership', 'class', 'task');
}

function advisingUpload(AdvisoryTask $task, User $student): string
{
    return UploadedFile::fake()->createWithContent('grades.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF")
        ->store(app(Advising::class)->folder($task, $student), Advising::DISK);
}

it('syncs the section roster without duplicates and retains submitted history', function () {
    extract(advisingFixture());
    $service = app(Advising::class);
    $service->syncRoster($task);
    $service->syncRoster($task);
    expect($task->submissions()->count())->toBe(1)->and($class->students()->count())->toBe(1);
    $file = advisingUpload($task, $student);
    $service->submit($task, $student, [$file], 'My documents');
    $membership->delete();
    $service->syncRoster($task);
    expect($task->submissions()->count())->toBe(1)->and($service->tasksFor($student, $campus->id)->count())->toBe(0);
});

it('allows the adviser to review files and returns actionable feedback to the student', function () {
    extract(advisingFixture());
    $service = app(Advising::class);
    $file = advisingUpload($task, $student);
    $row = $service->submit($task, $student, [$file], 'Please review');
    $service->review($row, 'returned', 'Upload a clearer screenshot.', $instructor);
    expect($row->refresh()->status)->toBe('returned')->and($service->toDoCount($student, $campus->id))->toBe(1);
    $next = advisingUpload($task, $student);
    $service->submit($task, $student, [$next], null);
    Storage::disk(Advising::DISK)->assertMissing($file);
    $service->review($row, 'approved', 'Complete', $instructor);
    expect($service->toDoCount($student, $campus->id))->toBe(0)->and($student->notifications()->count())->toBe(2);
    expect(fn () => $service->submit($task, $student, [$next], null))->toThrow(ValidationException::class);
    Storage::disk(Advising::DISK)->assertExists($next);
});

it('rejects outsiders, another student folder, missing files and invalid file types', function () {
    extract(advisingFixture());
    $service = app(Advising::class);
    $other = User::factory()->create(['is_student' => true]);
    $other->givePermissionTo('page_MyAdvising');
    $file = advisingUpload($task, $other);
    expect(fn () => $service->submit($task, $other, [$file], null))->toThrow(ValidationException::class);
    expect(fn () => $service->submit($task, $student, [$file], null))->toThrow(ValidationException::class);
    expect(fn () => $service->submit($task, $student, [$service->folder($task, $student).'/missing.pdf'], null))->toThrow(ValidationException::class);
    $bad = $service->folder($task, $student).'/bad.pdf';
    Storage::disk(Advising::DISK)->put($bad, '<script>alert(1)</script>');
    expect(fn () => $service->submit($task, $student, [$bad], null))->toThrow(ValidationException::class);
});

it('enforces deadlines and current task state even when a stale page is submitted', function () {
    extract(advisingFixture());
    $service = app(Advising::class);
    $file = advisingUpload($task, $student);
    $task->update(['due_at' => now()->subMinute()]);
    expect(fn () => $service->submit($task, $student, [$file], null))->toThrow(ValidationException::class);
    $task->update(['allow_late' => true]);
    $service->submit($task, $student, [$file], null);
    AdvisoryTask::whereKey($task)->update(['status' => 'closed']);
    expect(fn () => $service->submit($task, $student, [$file], null))->toThrow(ValidationException::class);
    $task->refresh()->update(['status' => 'archived']);
    expect($service->tasksFor($student, $campus->id)->count())->toBe(0);
});

it('scopes tasks and file downloads to the student, adviser and campus', function () {
    extract(advisingFixture());
    $row = app(Advising::class)->submit($task, $student, [advisingUpload($task, $student)], null);
    $url = route('advising.file', ['submission' => $row, 'index' => 0]);
    $this->get($url)->assertRedirect();
    $this->actingAs($student)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($instructor)->get($url)->assertOk();
    $other = User::factory()->create(['is_student' => true]);
    $other->givePermissionTo('page_MyAdvising');
    $other->campuses()->attach($campus);
    $this->actingAs($other)->get($url)->assertForbidden();
    expect(fn () => app(Advising::class)->review($row, 'approved', null, $other))->toThrow(AuthorizationException::class);
    $this->actingAs($student)->get(route('advising.file', ['submission' => $row, 'index' => 10]))->assertNotFound();
    $otherCampus = Campus::create(['name' => 'Other', 'slug' => 'other', 'is_active' => true]);
    expect(app(Advising::class)->tasksFor($student, $otherCampus->id)->count())->toBe(0);
    Filament::setTenant($otherCampus, isQuiet: true);
    $this->actingAs($instructor);
    expect(AdvisoryTaskResource::getEloquentQuery()->count())->toBe(0);
});

it('renders the student requirements and dashboard and accepts a PDF through Livewire', function () {
    extract(advisingFixture());
    $this->actingAs($student);
    Livewire::test(MyAdvising::class)->assertSuccessful()->assertCanSeeTableRecords([$task])
        ->callTableAction('submit', $task, data: ['files' => [UploadedFile::fake()->createWithContent('form.pdf', "%PDF-1.4\n%%EOF")], 'note' => 'Attached'])
        ->assertHasNoTableActionErrors();
    expect($task->submissions()->sole()->status)->toBe('submitted');
    $task->submissions()->update(['status' => 'returned']);
    expect(app(StudentTodo::class)->for($student, $campus->id)['advising']->modelKeys())->toBe([$task->id]);
    $this->get(MyAdvising::getUrl())->assertOk();
    expect(app(UserAccessAdministration::class)->studentPermissionNames())->toContain('page_MyAdvising');
});

it('renders adviser pages and the submissions review table', function () {
    extract(advisingFixture());
    $this->actingAs($instructor);
    Livewire::test(ManageAdvisoryClasses::class)->assertSuccessful()->assertCanSeeTableRecords([$class]);
    Livewire::test(CreateAdvisoryTask::class)->assertSuccessful()->fillForm([
        'advisory_class_id' => $class->id, 'title' => 'Second advising task', 'requirements' => ['Registration form'],
        'advising_semester_id' => $membership->semester_id,
        'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'status' => 'open',
    ])->call('create')->assertHasNoFormErrors();
    expect(AdvisoryTask::where('title', 'Second advising task')->sole()->submissions()->count())->toBe(1);
    Livewire::test(EditAdvisoryTask::class, ['record' => $task->id])->assertSuccessful();
    Livewire::test(ManageSubmissions::class, ['record' => $task->id])->assertSuccessful();
    $other = User::factory()->create(['is_instructor' => true]);
    $other->givePermissionTo($instructor->getAllPermissions());
    $this->actingAs($other);
    expect(AdvisoryClassResource::getEloquentQuery()->count())->toBe(0)->and(AdvisoryTaskResource::getEloquentQuery()->count())->toBe(0);
});

it('uses the first semester roster for second semester advising without moving student records or submissions', function () {
    extract(advisingFixture());
    $semester = Semester::create([
        'school_year_id' => $class->section->semester->school_year_id, 'name' => 'Second Semester',
        'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => true,
    ]);
    $row = app(Advising::class)->submit($task, $student, [advisingUpload($task, $student)], null);
    $firstSemester = $membership->semester_id;
    $originalFiles = $row->files;
    $this->actingAs($instructor);
    Livewire::test(EditAdvisoryTask::class, ['record' => $task->id])->fillForm(['advising_semester_id' => $semester->id])
        ->call('save')->assertHasNoFormErrors();
    expect($task->refresh()->advising_semester_id)->toBe($semester->id)
        ->and($membership->refresh()->semester_id)->toBe($firstSemester)
        ->and($class->refresh()->academic_section_id)->toBe($membership->academic_section_id)
        ->and($row->refresh()->files)->toBe($originalFiles)
        ->and($row->status)->toBe('submitted');
    $this->actingAs($student);
    Livewire::test(MyAdvising::class)->assertCanSeeTableRecords([$task])->assertSee('Second Semester')
        ->mountTableAction('details', $task)->assertSee('Advising for:')->assertSee('Second Semester');
    expect(app(StudentTodo::class)->for($student, $campus->id)['advising'])->toHaveCount(0);
    $row->update(['status' => 'returned']);
    expect(app(StudentTodo::class)->for($student, $campus->id)['advising']->sole()->advising_term)->toContain('Second Semester');
});

it('rejects a target advising semester from another campus', function () {
    extract(advisingFixture());
    $foreignCampus = Campus::create(['name' => 'Foreign campus', 'slug' => 'foreign', 'is_active' => true]);
    $year = SchoolYear::create(['campus_id' => $foreignCampus->id, 'name' => '2026-2027', 'start_date' => '2026-06-01', 'end_date' => '2027-05-31', 'is_current' => true]);
    $semester = Semester::create(['school_year_id' => $year->id, 'name' => 'Second Semester', 'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => true]);
    $this->actingAs($instructor);
    Livewire::test(EditAdvisoryTask::class, ['record' => $task->id])->fillForm(['advising_semester_id' => $semester->id])
        ->call('save')->assertHasFormErrors(['advising_semester_id']);
    expect($task->refresh()->advising_semester_id)->toBe($membership->semester_id);
});

it('backfills the original advising term when upgrading existing task data', function () {
    extract(advisingFixture());
    $migration = require database_path('migrations/2026_11_11_000002_add_advising_semester_to_tasks.php');
    $migration->down();
    $migration->up();
    expect($task->refresh()->advising_semester_id)->toBe($membership->semester_id)
        ->and($membership->refresh()->semester_id)->toBe($class->section->semester_id);
});

it('claims a free section as the signed-in adviser and preserves review feedback', function () {
    extract(advisingFixture());
    $next = AcademicSection::create([
        'department_id' => $membership->department_id, 'semester_id' => $membership->semester_id,
        'name' => 'BSIT 3B', 'is_active' => true,
    ]);
    $this->actingAs($instructor);
    Livewire::test(ManageAdvisoryClasses::class)->callAction('create', data: [
        'school_year_id' => $next->semester->school_year_id, 'semester_id' => $next->semester_id, 'academic_section_id' => $next->id,
    ])->assertHasNoActionErrors();
    expect(AdvisoryClass::where('academic_section_id', $next->id)->sole()->adviser_id)->toBe($instructor->id);
    $row = app(Advising::class)->submit($task, $student, [advisingUpload($task, $student)], null);
    Livewire::test(ReviewSubmission::class, ['record' => $task->id, 'submission' => $row->id])
        ->fillForm(['status' => 'returned', 'feedback' => 'Please include your grades.'])->call('save')->assertHasNoFormErrors();
    $this->actingAs($student);
    Livewire::test(MyAdvising::class)->mountTableAction('details', $task)
        ->assertSee('Please include your grades.')->assertSee('Grades screenshot')->assertSee(AdvisoryTask::DEFAULT_CONSEQUENCE);
});

it('edits the advisory semester and section and refreshes untouched task rosters', function () {
    extract(advisingFixture());
    app(Advising::class)->syncRoster($task);
    $semester = Semester::create([
        'school_year_id' => $class->section->semester->school_year_id, 'name' => 'Second Semester',
        'start_date' => '2027-01-01', 'end_date' => '2027-05-31', 'is_active' => true,
    ]);
    $nextStudent = User::factory()->create(['is_student' => true]);
    $nextMembership = StudentSectionMembership::create([
        'user_id' => $nextStudent->id, 'department_id' => $membership->department_id,
        'semester_id' => $semester->id, 'section' => 'BSIT 3A',
    ]);
    $this->actingAs($instructor);
    Livewire::test(ManageAdvisoryClasses::class)->mountTableAction('edit', $class)
        ->assertTableActionDataSet(['school_year_id' => $semester->school_year_id, 'semester_id' => $membership->semester_id, 'academic_section_id' => $membership->academic_section_id])
        ->setTableActionData(['school_year_id' => $semester->school_year_id, 'semester_id' => $semester->id, 'academic_section_id' => $nextMembership->academic_section_id])
        ->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($class->refresh()->academic_section_id)->toBe($nextMembership->academic_section_id)
        ->and($task->submissions()->pluck('user_id')->all())->toBe([$nextStudent->id]);
});

it('keeps submissions in their original term and rejects sections claimed by another adviser', function () {
    extract(advisingFixture());
    $next = AcademicSection::create([
        'department_id' => $membership->department_id, 'semester_id' => $membership->semester_id, 'name' => 'BSIT 3B', 'is_active' => true,
    ]);
    $this->actingAs($instructor);
    $service = app(Advising::class);
    $row = $service->submit($task, $student, [advisingUpload($task, $student)], null);
    Livewire::test(ManageAdvisoryClasses::class)->callTableAction('edit', $class, data: [
        'school_year_id' => $class->section->semester->school_year_id, 'semester_id' => $next->semester_id, 'academic_section_id' => $next->id,
    ])->assertHasTableActionErrors(['academic_section_id']);
    expect($class->refresh()->academic_section_id)->toBe($membership->academic_section_id)->and($row->refresh()->status)->toBe('submitted');
    expect($service->changeSection($class, $class->academic_section_id, $instructor)->id)->toBe($class->id);
    $row->delete();
    AdvisoryClass::create(['academic_section_id' => $next->id, 'adviser_id' => User::factory()->create()->id]);
    expect(fn () => $service->changeSection($class, $next->id, $instructor))->toThrow(ValidationException::class);
    expect(fn () => $service->changeSection($class, $next->id, $student))->toThrow(AuthorizationException::class);
});

it('keeps the student role valid and serves adviser pages through real middleware', function () {
    extract(advisingFixture());
    $role = $student->roles()->first();
    $role->givePermissionTo('page_MyAdvising');
    $this->actingAs($student)->get(MyAdvising::getUrl())->assertOk();
    expect($student->fresh()->hasRole('student'))->toBeTrue();
    $instructor->assignRole(Role::findOrCreate('instructor', 'web'));
    $this->actingAs($instructor)->get(AdvisoryClassResource::getUrl())->assertOk();
    $this->get(AdvisoryTaskResource::getUrl())->assertOk();
    $this->get(AdvisoryTaskResource::getUrl('edit', ['record' => $task]))->assertOk();
});

it('lets the adviser choose which file types a task accepts, and enforces them on upload and on the server', function () {
    extract(advisingFixture());
    $service = app(Advising::class);

    // Tasks made before the choice existed keep accepting screenshots and PDF.
    expect($task->fileTypes())->toBe(AdvisoryTask::DEFAULT_FILE_TYPES)
        ->and($task->acceptsMime('application/pdf'))->toBeTrue()
        ->and($task->acceptsMime('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeFalse();

    $this->actingAs($instructor);
    Livewire::test(CreateAdvisoryTask::class)
        ->assertFormSet(['accepted_types' => AdvisoryTask::DEFAULT_FILE_TYPES])
        ->fillForm([
            'advisory_class_id' => $class->id, 'title' => 'Word only', 'requirements' => ['Advising form'],
            'advising_semester_id' => $membership->semester_id, 'accepted_types' => ['word'],
            'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'status' => 'open',
        ])->call('create')->assertHasNoFormErrors();
    $word = AdvisoryTask::where('title', 'Word only')->sole();
    expect($word->accepted_types)->toBe(['word'])->and($word->fileTypesLabel())->toBe('Word');

    Livewire::test(CreateAdvisoryTask::class)
        ->fillForm([
            'advisory_class_id' => $class->id, 'title' => 'Nothing', 'requirements' => ['Form'],
            'advising_semester_id' => $membership->semester_id, 'accepted_types' => [],
            'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'status' => 'open',
        ])->call('create')->assertHasFormErrors(['accepted_types' => 'required']);

    // A PDF is refused by a Word-only task, even if a stale page sends it.
    $pdf = advisingUpload($word, $student);
    expect(fn () => $service->submit($word, $student, [$pdf], null))->toThrow(ValidationException::class);

    $path = $service->folder($word, $student).'/form.docx';
    Storage::disk(Advising::DISK)->put($path, advisingDocx());
    expect($service->submit($word, $student, [$path], null)->status)->toBe('submitted');

    // The student sees which types are accepted.
    $this->actingAs($student);
    Livewire::test(MyAdvising::class)->mountTableAction('submit', $word)->assertSee('Accepted: Word');
});

/** A minimal genuine Word file, so the server detects it the way it detects a real upload. */
function advisingDocx(): string
{
    $file = tempnam(sys_get_temp_dir(), 'docx');
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships/>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><document/>');
    $zip->close();
    $bytes = file_get_contents($file);
    unlink($file);

    return $bytes;
}

it('reviews submissions on their own pages, apart from the task settings, one by one or in bulk', function () {
    extract(advisingFixture());
    $service = app(Advising::class);
    $second = User::factory()->create(['is_student' => true, 'name' => 'Zed Student']);
    $second->givePermissionTo('page_MyAdvising');
    StudentSectionMembership::create(['user_id' => $second->id, 'department_id' => $membership->department_id, 'semester_id' => $membership->semester_id, 'section' => 'BSIT 3A']);
    $service->syncRoster($task);
    $first = $service->submit($task, $student, [advisingUpload($task, $student)], 'Here are my grades');
    $other = $service->submit($task, $second, [advisingUpload($task, $second)], null);
    $this->actingAs($instructor);

    // The task's edit page no longer lists students; it links to the submissions page.
    expect(AdvisoryTaskResource::getRelations())->toBe([]);
    Livewire::test(EditAdvisoryTask::class, ['record' => $task->id])->assertActionVisible('submissions')->assertDontSee($first->student_name);
    Livewire::test(ManageSubmissions::class, ['record' => $task->id])->assertCanSeeTableRecords([$first, $other]);

    // One student's page shows the files inline and saves a status, then moves to the next one waiting.
    Livewire::test(ReviewSubmission::class, ['record' => $task->id, 'submission' => $first->id])
        ->assertSee('Here are my grades')
        ->assertSee(route('advising.file', ['submission' => $first->id, 'index' => 0]).'?inline=1', false)
        ->fillForm(['status' => 'returned', 'feedback' => ''])->call('save')->assertHasFormErrors(['feedback' => 'required'])
        ->fillForm(['status' => 'approved'])->call('save')->assertHasNoFormErrors()
        ->assertRedirect(ReviewSubmission::getUrl(['record' => $task, 'submission' => $other->id]));
    expect($first->fresh()->status)->toBe('approved')->and($first->fresh()->reviewed_by)->toBe($instructor->id);
    $this->get(route('advising.file', ['submission' => $first->id, 'index' => 0]).'?inline=1')
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($this->get(route('advising.file', ['submission' => $first->id, 'index' => 0]).'?inline=1')->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($this->get(route('advising.file', ['submission' => $first->id, 'index' => 0]))->headers->get('Content-Disposition'))->toStartWith('attachment');

    // Bulk status update skips students who have not submitted.
    $waiting = $task->submissions()->where('status', 'pending')->get();
    Livewire::test(ManageSubmissions::class, ['record' => $task->id])
        ->callTableBulkAction('setStatus', $task->submissions()->get(), data: ['status' => 'returned', 'feedback' => 'Grades are cut off'])
        ->assertHasNoTableBulkActionErrors();
    expect($other->fresh()->status)->toBe('returned')->and($other->fresh()->feedback)->toBe('Grades are cut off');
    $waiting->each(fn ($row) => expect($row->fresh()->status)->toBe('pending'));

    // Another adviser cannot open these pages.
    $outsider = User::factory()->create(['is_instructor' => true]);
    $outsider->givePermissionTo($instructor->getAllPermissions());
    $outsider->campuses()->attach($campus);
    $this->actingAs($outsider);
    expect(fn () => Livewire::test(ReviewSubmission::class, ['record' => $task->id, 'submission' => $first->id]))->toThrow(Exception::class);
});

it('drops a deleted student account, such as a duplicate, from the task list but keeps work it already sent', function () {
    extract(advisingFixture());
    $service = app(Advising::class);
    $duplicate = User::factory()->create(['is_student' => true, 'name' => 'Duplicate Account']);
    $sender = User::factory()->create(['is_student' => true, 'name' => 'Deleted After Sending']);
    $sender->givePermissionTo('page_MyAdvising');
    foreach ([$duplicate, $sender] as $user) {
        StudentSectionMembership::create(['user_id' => $user->id, 'department_id' => $membership->department_id, 'semester_id' => $membership->semester_id, 'section' => 'BSIT 3A']);
    }
    $service->syncRoster($task);
    $service->submit($task, $sender, [advisingUpload($task, $sender)], null);
    expect($task->submissions()->count())->toBe(3);

    $duplicate->delete();
    $sender->delete();
    $service->syncRoster($task);

    expect($task->submissions()->pluck('user_id')->sort()->values()->all())->toBe(collect([$student->id, $sender->id])->sort()->values()->all());
});
