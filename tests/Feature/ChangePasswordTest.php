<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * The shared starting password: staff imported with it must choose their own
 * before they can use the panel.
 */
function staffOnStartingPassword(): User
{
    $admin = User::factory()->create(['password' => Hash::make('password123')]);
    $admin->forceFill(['must_change_password' => true])->save();
    $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

    return $admin;
}

it('sends staff on the starting password to the change page', function () {
    $admin = staffOnStartingPassword();

    $this->actingAs($admin)->get(route('password.change'))->assertOk();
});

it('sends a staff member who already chose a password back to the panel', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->get(route('password.change'))->assertRedirect('/');
});

it('keeps a password that is too short or not confirmed', function () {
    $admin = staffOnStartingPassword();

    $this->actingAs($admin)
        ->post(route('password.change'), ['password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');

    $this->actingAs($admin)
        ->post(route('password.change'), ['password' => 'a-new-secret', 'password_confirmation' => 'different'])
        ->assertSessionHasErrors('password');

    expect($admin->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('password123', $admin->fresh()->password))->toBeTrue();
});

it('refuses a password that is the starting password again', function () {
    $admin = staffOnStartingPassword();

    $this->actingAs($admin)
        ->post(route('password.change'), ['password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertSessionHasErrors('password');

    expect($admin->fresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('password123', $admin->fresh()->password))->toBeTrue();
});

it('lets a guest reach neither the form nor the update', function () {
    $this->get(route('password.change'))->assertRedirect();
    $this->post(route('password.change'), ['password' => 'a-new-secret'])->assertRedirect();
});
