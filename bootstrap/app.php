<?php

use App\Http\Middleware\EnsureCampusAccess;
use App\Http\Middleware\MeasureLoadRequest;
use App\Support\ErrorPage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
            MeasureLoadRequest::class,
        ]);
        // The Filament panel owns sign-in; there is no route named "login".
        $middleware->redirectGuestsTo('/login');
        // A student's own project posts its forms without the app's CSRF token.
        $middleware->validateCsrfTokens(except: ['project-run/*']);
        // The classroom server's gateway passes on each device's address, so sign-in
        // limits and logs see the student's own device instead of the gateway.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->context(fn () => ['error_reference' => ErrorPage::reference(request())]);
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if (! in_array($status, [401, 403, 404, 405, 408, 413, 419, 422, 429, 500, 502, 503, 504], true)) {
                return $response;
            }
            if (! $request->expectsJson()) {
                $headers = $response->headers->all();
                unset($headers['content-type'], $headers['content-length']);
                $response = response()->view('errors.application', ErrorPage::data($request, $status, $exception), $status, $headers);
            }
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            if ($status >= 500) {
                $response->headers->set('X-Error-Reference', ErrorPage::reference($request));
            }

            return $response;
        });
    })->create();
