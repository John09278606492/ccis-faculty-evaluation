<?php

use App\Filament\Pages\Login;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Models\User;
use App\Services\StudentPins;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('does not let a student sign in with only their ID number', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);

    Livewire::test(Login::class)
        ->set('data.login', '2026-0001')
        ->call('authenticate')
        ->assertHasErrors('data.password');

    expect(auth()->check())->toBeFalse();
});

it('signs a student in with their ID number and issued PIN', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);
    $pin = StudentPins::issue(collect([$student]))->first()['pin'];

    expect($pin)->toMatch('/^\d{6}$/')
        ->and($student->fresh()->pin_issued_at)->not->toBeNull();

    Livewire::test(Login::class)
        ->set('data.login', '2026-0001')
        ->set('data.password', $pin === '000000' ? '111111' : '000000')
        ->call('authenticate')
        ->assertHasErrors('data.login');

    Livewire::test(Login::class)
        ->set('data.login', '2026-0001')
        ->set('data.password', $pin)
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($student->id);
});

it('issues PINs for the class list and downloads them once', function () {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);
    $classmate = User::factory()->create(['is_student' => true, 'student_id' => '2026-0002']);
    $classmate->assignRole('student');
    $offering->students()->attach($classmate, ['enrolled_at' => now()]);
    StudentPins::issue(collect([$classmate]));
    $classmatePassword = $classmate->fresh()->password;

    $instructor = $offering->instructor;
    foreach (['view_course::offering', 'update_course::offering'] as $permission) {
        $instructor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    Filament::setTenant($offering->semester->schoolYear->campus, isQuiet: true);

    // Another instructor with the same permissions cannot reset this class's PINs.
    $other = User::factory()->create(['is_instructor' => true]);
    $other->givePermissionTo(['view_course::offering', 'update_course::offering']);
    $this->actingAs($other);
    expect(StudentsRelationManager::canViewForRecord($offering, EditCourseOffering::class))->toBeFalse();

    $this->actingAs($instructor);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $offering, 'pageClass' => EditCourseOffering::class])
        ->callTableAction('signInPins', data: ['scope' => 'missing'])
        ->assertFileDownloaded();

    expect($student->fresh()->pin_issued_at)->not->toBeNull()
        ->and($classmate->fresh()->password)->toBe($classmatePassword);
});

it('makes staff on the shared starting password choose their own', function () {
    ['offering' => $offering] = evaluationFixture();
    $admin = User::factory()->create(['password' => Hash::make('password123')]);
    $admin->forceFill(['must_change_password' => true])->save();
    $admin->assignRole(Role::findByName('super_admin'));
    $campus = $offering->semester->schoolYear->campus;

    $this->actingAs($admin)->get('/'.$campus->id)->assertRedirect(route('password.change'));

    $this->actingAs($admin)->post(route('password.change'), ['password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertSessionHasErrors('password');

    $this->actingAs($admin)->post(route('password.change'), ['password' => 'a-new-secret', 'password_confirmation' => 'a-new-secret'])
        ->assertRedirect('/');

    expect($admin->fresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('a-new-secret', $admin->fresh()->password))->toBeTrue();
});
