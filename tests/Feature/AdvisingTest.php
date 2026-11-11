<?php

use App\Filament\Pages\MyAdvising;
use App\Filament\Resources\AdvisoryClassResource;
use App\Filament\Resources\AdvisoryClassResource\Pages\ManageAdvisoryClasses;
use App\Filament\Resources\AdvisoryTaskResource;
use App\Filament\Resources\AdvisoryTaskResource\Pages\CreateAdvisoryTask;
use App\Filament\Resources\AdvisoryTaskResource\Pages\EditAdvisoryTask;
use App\Filament\Resources\AdvisoryTaskResource\RelationManagers\SubmissionsRelationManager;
use App\Models\AcademicSection;
use App\Models\AdvisoryClass;
use App\Models\AdvisoryTask;
use App\Models\Campus;
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
    $task = AdvisoryTask::create(['advisory_class_id' => $class->id, 'title' => 'Enrollment advising', 'requirements' => ['Grades screenshot', 'Advising form PDF'], 'due_at' => now()->addDay(), 'consequence' => AdvisoryTask::DEFAULT_CONSEQUENCE, 'status' => 'open']);
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
        'due_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'status' => 'open',
    ])->call('create')->assertHasNoFormErrors();
    expect(AdvisoryTask::where('title', 'Second advising task')->sole()->submissions()->count())->toBe(1);
    Livewire::test(EditAdvisoryTask::class, ['record' => $task->id])->assertSuccessful();
    Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => EditAdvisoryTask::class])->assertSuccessful();
    $other = User::factory()->create(['is_instructor' => true]);
    $other->givePermissionTo($instructor->getAllPermissions());
    $this->actingAs($other);
    expect(AdvisoryClassResource::getEloquentQuery()->count())->toBe(0)->and(AdvisoryTaskResource::getEloquentQuery()->count())->toBe(0);
});

it('claims a free section as the signed-in adviser and preserves review feedback', function () {
    extract(advisingFixture());
    $next = AcademicSection::create([
        'department_id' => $membership->department_id, 'semester_id' => $membership->semester_id,
        'name' => 'BSIT 3B', 'is_active' => true,
    ]);
    $this->actingAs($instructor);
    Livewire::test(ManageAdvisoryClasses::class)->callAction('create', data: ['academic_section_id' => $next->id])->assertHasNoActionErrors();
    expect(AdvisoryClass::where('academic_section_id', $next->id)->sole()->adviser_id)->toBe($instructor->id);
    $row = app(Advising::class)->submit($task, $student, [advisingUpload($task, $student)], null);
    Livewire::test(SubmissionsRelationManager::class, ['ownerRecord' => $task, 'pageClass' => EditAdvisoryTask::class])
        ->callTableAction('review', $row, data: ['status' => 'returned', 'feedback' => 'Please include your grades.'])->assertHasNoTableActionErrors();
    $this->actingAs($student);
    Livewire::test(MyAdvising::class)->mountTableAction('details', $task)
        ->assertSee('Please include your grades.')->assertSee('Grades screenshot')->assertSee(AdvisoryTask::DEFAULT_CONSEQUENCE);
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
