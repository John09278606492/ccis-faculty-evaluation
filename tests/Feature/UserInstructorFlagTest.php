<?php

use App\Models\User;

it('knows whether a user is an instructor', function () {
    $user = User::factory()->create();

    expect($user->isInstructor())->toBeFalse();

    $instructor = User::factory()->create(['is_instructor' => true]);
    expect($instructor->isInstructor())->toBeTrue();
});

it('knows whether a user is a student', function () {
    $user = User::factory()->create();

    expect($user->isStudent())->toBeFalse();

    $student = User::factory()->create(['is_student' => true]);
    expect($student->isStudent())->toBeTrue();
});
