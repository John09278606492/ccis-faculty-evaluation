<?php

use App\Filament\Pages\Login;
use App\Filament\Resources\CourseOfferingResource\Pages\EditCourseOffering;
use App\Filament\Resources\CourseOfferingResource\RelationManagers\StudentsRelationManager;
use App\Models\User;
use App\Services\StudentPins;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('does not let a student sign in with only their ID number', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => '2026-0001']);
    StudentPins::issue(collect([$student]));

    Livewire::test(Login::class)
        ->set('data.login', '2026-0001')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertSet('studentIdentifier', '2026-0001')
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
        ->call('authenticate')
        ->set('data.password', $pin === '000000' ? '111111' : '000000')
        ->call('authenticate')
        ->assertHasErrors('data.login');

    Livewire::test(Login::class)
        ->set('data.login', '2026-0001')
        ->call('authenticate')
        ->set('data.password', $pin)
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($student->id);
});

it('redirects students without an assigned PIN to the waiting page without signing them in', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => '2026-WAIT']);
    Livewire::test(Login::class)->set('data.login', '2026-WAIT')
        ->call('authenticate')->assertRedirect(route('student.pin-pending'));
    expect(auth()->check())->toBeFalse();
    $this->get(route('student.pin-pending'))->assertOk()->assertSee('instructor, admin or super-admin');
});

it('does not permit student accounts to bypass PIN assignment through staff login', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['password' => Hash::make('legacy-password')]);
    Livewire::test(Login::class)->call('toggleStaffLogin')
        ->set('data.login', $student->email)->set('data.password', 'legacy-password')
        ->call('authenticate')->assertHasErrors('data.login');
    expect(auth()->check())->toBeFalse();
});

it('allows a waiting student to continue after a PIN is issued and reset the selected ID', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => '2026-READY']);
    StudentPins::issue(collect([$student]));
    Livewire::test(Login::class)->set('data.login', '2026-READY')->call('authenticate')
        ->assertSet('studentIdentifier', '2026-READY')->assertHasNoErrors()
        ->call('changeStudentId')->assertSet('studentIdentifier', null)->assertSet('data.password', '');
    expect(auth()->check())->toBeFalse();
});

it('keeps PIN controls out of the evaluation class list', function () {
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
        ->assertTableActionDoesNotExist('signInPins')
        ->assertTableActionDoesNotExist('approveSignIn');

    expect($student->fresh()->pin_issued_at)->toBeNull()
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

it('keeps evaluation PIN controls absent for administrative roles', function (string $roleName) {
    ['student' => $student, 'offering' => $offering] = evaluationFixture();
    $admin = User::factory()->create(['is_student' => false]);
    $role = Role::findOrCreate($roleName, 'web');
    foreach (['view_any_user', 'view_course::offering', 'update_course::offering'] as $permission) {
        $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $admin->assignRole($role);
    Filament::setTenant($offering->semester->schoolYear->campus, isQuiet: true);
    $this->actingAs($admin);
    Livewire::test(StudentsRelationManager::class, ['ownerRecord' => $offering, 'pageClass' => EditCourseOffering::class])
        ->assertTableActionDoesNotExist('signInPins')->assertTableActionDoesNotExist('approveSignIn');
    expect($student->fresh()->pin_issued_at)->toBeNull();
})->with(['admin', 'super_admin']);

it('keeps staff password authentication available', function () {
    evaluationFixture();
    $admin = User::factory()->create(['is_student' => false, 'password' => Hash::make('Staff-Secret!2026')]);
    $admin->assignRole('super_admin');
    Livewire::test(Login::class)->call('toggleStaffLogin')
        ->set('data.login', $admin->email)->set('data.password', 'Staff-Secret!2026')
        ->call('authenticate')->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);
});

it('rechecks PIN assignment before authenticating a selected student', function () {
    ['student' => $student] = evaluationFixture();
    $student->update(['student_id' => '2026-RECHECK']);
    $pin = StudentPins::issue(collect([$student]))->first()['pin'];
    $component = Livewire::test(Login::class)->set('data.login', '2026-RECHECK')->call('authenticate');
    $student->forceFill(['pin_issued_at' => null])->save();
    $component->set('data.password', $pin)->call('authenticate')->assertRedirect(route('student.pin-pending'));
    expect(auth()->check())->toBeFalse();
});

it('finds the My Profile forms on form requests, before the panel boots', function () {
    $registry = app(ComponentRegistry::class);

    foreach (['personal_info', 'update_password', 'two_factor_authentication'] as $name) {
        expect($registry->getClass($name))->not->toBeNull();
    }
});
