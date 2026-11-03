<?php

use App\Models\Campus;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Spatie\Permission\Models\Role;

it('sets up a new installation with the default roles, a campus and the first super admin', function () {
    Campus::query()->delete();

    $this->artisan('classroom:install')
        ->expectsQuestion('Name', 'Val')
        ->expectsQuestion('Email', 'val@example.test')
        ->expectsQuestion('Password (at least 8 characters)', 'secret123')
        ->assertSuccessful();

    $admin = User::where('email', 'val@example.test')->first();
    expect($admin->hasRole('super_admin'))->toBeTrue()
        ->and($admin->campuses()->count())->toBe(1)
        ->and(Role::findByName('instructor')->hasPermissionTo('page_QuizBuilder'))->toBeTrue()
        ->and(Role::findByName('student')->hasPermissionTo('page_TakeQuiz'))->toBeTrue();
});

it('keeps permissions an admin added when the roles are seeded again', function () {
    $this->seed(RolesSeeder::class);
    Role::findByName('instructor')->givePermissionTo('view_any_form');

    $this->seed(RolesSeeder::class);

    expect(Role::findByName('instructor')->hasPermissionTo('view_any_form'))->toBeTrue();
});
