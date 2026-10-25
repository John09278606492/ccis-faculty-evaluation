<?php

namespace App\Support;

use Illuminate\Http\Request;
use Throwable;

class ErrorPage
{
    public static function reference(Request $request): string
    {
        if (! $request->attributes->has('error_reference')) {
            $request->attributes->set('error_reference', 'ERR-'.strtoupper(bin2hex(random_bytes(6))));
        }

        return $request->attributes->get('error_reference');
    }

    public static function data(Request $request, int $status, ?Throwable $exception = null): array
    {
        $report = $request->is('evaluation-tabular-reports/*', 'evaluation-reports/*', 'evaluation-copies/*');
        [$title, $message] = match ($status) {
            401 => ['Please sign in again', 'Your session is no longer available. Sign in to continue.'],
            403 => ['This page is restricted', 'Your account does not have access to this page, campus or department. Use your assigned campus or ask an administrator to review your access.'],
            404 => ['We could not find that page', 'The link may be outdated, or this record may no longer be available. Return to your workspace and open it again.'],
            405 => ['This action is not available', 'Open the page from your workspace and try the action again.'],
            408, 504 => ['The request took too long', 'The server did not finish in time. Wait a moment, then try opening the page again.'],
            413 => ['This upload is too large', 'Return to the upload form and use a file within the size limit shown there.'],
            419 => ['Your session has expired', 'Sign in again, then return to the form and review it before submitting.'],
            422 => ['Some information needs checking', 'Return to the form, review the entered information and submit it again.'],
            429 => ['Please wait a moment', 'There have been too many requests. Wait briefly before trying again.'],
            503 => ['The service is temporarily unavailable', 'Wait a moment and try again. If this continues, ask your campus system administrator to check the service.'],
            default => [$report ? 'We could not open this report' : 'Something interrupted this page', 'Please try again. If the problem continues, share the reference below with your campus system administrator.'],
        };
        // These two deliberate workspace messages are safe; arbitrary exception details are private.
        $publicMessages = [
            'The student database server is not running. Ask your instructor to start it.',
            'Your database could not be opened. Try again in a moment, or ask your instructor to check the student database server.',
        ];
        if ($status === 503 && $exception && in_array($exception->getMessage(), $publicMessages, true)) {
            $message = $exception->getMessage();
        }

        return ['status' => $status, 'title' => $title, 'message' => $message, 'isReport' => $report,
            'reference' => $status >= 500 ? self::reference($request) : null,
            'canRetry' => in_array($request->method(), ['GET', 'HEAD'], true) && in_array($status, [408, 429, 500, 502, 503, 504], true),
            'signIn' => in_array($status, [401, 419], true)];
    }
}
