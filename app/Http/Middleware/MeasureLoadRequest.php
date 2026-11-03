<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Diagnostic timings only in the isolated load environment; no SQL or identity logging. */
class MeasureLoadRequest
{
    public function handle(Request $request, Closure $next)
    {
        $file = env('LOAD_TEST_METRICS');
        if (! app()->environment('loadtesting') || ! $file || ! str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', storage_path('app/load-tests/')))) {
            return $next($request);
        }
        $queries = 0;
        $sqlMs = 0;
        $started = microtime(true);
        $status = 500;
        DB::listen(function ($query) use (&$queries, &$sqlMs) {
            $queries++;
            $sqlMs += $query->time;
        });
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            file_put_contents($file, json_encode(['route' => $request->route()?->getName(), 'method' => $request->method(), 'status' => $status,
                'php_ms' => round((microtime(true) - $started) * 1000, 2), 'sql_ms' => round($sqlMs, 2), 'queries' => $queries, 'peak_mb' => memory_get_peak_usage(true) / 1048576])."\n", FILE_APPEND | LOCK_EX);
        }
    }
}
