<?php

use App\Models\CourseOffering;
use App\Models\User;
use App\Services\EvaluationSubmissionIdentity;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

it('creates stable opaque fingerprints without exposing the user id', function () {
    config()->set('evaluations.fingerprint_key', 'test-secret');
    $service = new EvaluationSubmissionIdentity;
    $offering = new CourseOffering(['section' => 'A']);
    $offering->id = 17;
    $user = new User;
    $user->id = 42;
    $request = Request::create('/evaluate/17');
    $request->setLaravelSession(new Store('test', new ArraySessionHandler(120)));

    $first = $service->fingerprint($offering, $request, $user);
    $second = $service->fingerprint($offering, $request, $user);

    expect($first)
        ->toBe($second)
        ->toHaveLength(64)
        ->not->toContain('42');
});
