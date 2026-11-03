<?php

use App\Support\ErrorPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('renders a branded report error and logs the same support reference without exposing internals', function () {
    config(['app.debug' => true]);
    Log::spy();
    Route::get('/evaluation-tabular-reports/qa-failure', fn () => throw new RuntimeException('SECRET_SQLSTATE-password-student-name'));
    $response = $this->get('/evaluation-tabular-reports/qa-failure')->assertStatus(500)
        ->assertSee('We could not open this report')->assertSee('Try again')->assertSee('Close preview')->assertSee('Go to workspace')
        ->assertDontSee('SECRET_SQLSTATE')->assertDontSee('RuntimeException')->assertDontSee('stacktrace');
    $reference = $response->headers->get('X-Error-Reference');
    expect($reference)->toMatch('/^ERR-[A-F0-9]{12}$/');
    $response->assertSee($reference);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $context['error_reference'] === $reference)->once();
    if (getenv('RENDER_ERROR_PAGE_QA')) {
        file_put_contents(storage_path('app/qa/report-error-page.html'), $response->getContent());
    }
});

it('provides clear recovery for common HTML error statuses', function (int $status, string $title) {
    Route::get('/qa-errors/'.$status, fn () => abort($status, 'PRIVATE error details'));
    $this->get('/qa-errors/'.$status)->assertStatus($status)->assertSee($title)->assertDontSee('PRIVATE error details');
})->with([
    [403, 'This page is restricted'], [404, 'We could not find that page'], [419, 'Your session has expired'],
    [429, 'Please wait a moment'], [503, 'The service is temporarily unavailable'], [504, 'The request took too long'],
]);

it('does not offer a reload that could resubmit a failed POST', function () {
    Route::post('/qa-errors/save', fn () => throw new RuntimeException('Private save failure'));
    $this->post('/qa-errors/save')->assertStatus(500)->assertDontSee('window.location.reload()', false)->assertDontSee('Private save failure');
});

it('preserves JSON error responses and Retry-After headers', function () {
    config(['app.debug' => false]);
    Route::get('/qa-errors/api', fn () => abort(403, 'Known API message'));
    $this->getJson('/qa-errors/api')->assertForbidden()->assertExactJson(['message' => 'Known API message']);
    Route::get('/qa-errors/throttled', fn () => throw new HttpException(429, 'Please wait.', null, ['Retry-After' => 60]));
    $this->get('/qa-errors/throttled')->assertStatus(429)->assertHeader('Retry-After', '60')->assertSee('Please wait a moment');
});

it('retains deliberate database-workspace recovery messages while hiding arbitrary server details', function () {
    $safe = 'Your database could not be opened. Try again in a moment, or ask your instructor to check the student database server.';
    Route::get('/qa-errors/workspace', fn () => abort(503, $safe));
    $this->get('/qa-errors/workspace')->assertStatus(503)->assertSee($safe);
    Route::get('/qa-errors/database', fn () => abort(503, 'PDOException: secret-database-password'));
    $this->get('/qa-errors/database')->assertStatus(503)->assertDontSee('secret-database-password');
});

it('renders the fallback without querying a database or depending on compiled frontend assets', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();
    $request = Request::create('/evaluation-reports/1');
    $data = ErrorPage::data($request, 500);
    $html = view('errors.application', $data)->render();
    expect(DB::getQueryLog())->toBeEmpty();
    expect($html)->toContain('Faculty Evaluation MIS')->toContain('width=device-width')->toContain('color-scheme:light')
        ->not->toContain('/build/')->not->toContain('fonts.googleapis.com');
    expect(ErrorPage::reference($request))->toBe($data['reference']);
    DB::disableQueryLog();
});
