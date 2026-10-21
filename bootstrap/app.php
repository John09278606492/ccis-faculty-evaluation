<?php

use App\Http\Middleware\EnsureCampusAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            EnsureCampusAccess::class,
        ]);
        // The Filament panel owns sign-in; there is no route named "login".
        $middleware->redirectGuestsTo('/login');
        // The classroom server's gateway passes on each device's address, so sign-in
        // limits and logs see the student's own device instead of the gateway.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
