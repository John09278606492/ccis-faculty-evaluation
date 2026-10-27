<?php

it('keeps the plain sign-in card with the evaluation button by default', function () {
    config(['classroom.login' => ['title' => null, 'tagline' => null, 'campus' => null, 'image' => null, 'logo' => null, 'evaluation_entry' => true]]);

    $this->get('/login')->assertOk()
        ->assertSee('Faculty evaluation — enter student ID')
        ->assertDontSee('ccis-brand-title', false);
});

it('shows the campus branding and hides the evaluation button for an assessment-only installation', function () {
    config(['classroom.login' => ['title' => 'SINAG', 'tagline' => 'Coding exams in one place.', 'campus' => 'BISU Balilihan Campus',
        'image' => 'images/missing.jpg', 'logo' => null, 'evaluation_entry' => false]]);

    $this->get('/login')->assertOk()
        ->assertSee('SINAG')
        ->assertSee('BISU Balilihan Campus')
        ->assertDontSee('Faculty evaluation — enter student ID')
        // A missing photo falls back to the gradient instead of a broken image.
        ->assertDontSee('images/missing.jpg');
});
