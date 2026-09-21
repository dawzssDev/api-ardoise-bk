<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequestMetrics
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('metrics.enabled')) {
            return $next($request);
        }

        $start = microtime(true);
        $cpu0  = getrusage();
        $queries = 0;
        $dbTime  = 0.0;

        DB::listen(function ($q) use (&$queries, &$dbTime) {
            $queries++;
            $dbTime += $q->time;
        });

        $response = $next($request);

        $cpu1 = getrusage();
        $cpuMs =
            (($cpu1['ru_utime.tv_sec']  - $cpu0['ru_utime.tv_sec'])  * 1000) +
            (($cpu1['ru_utime.tv_usec'] - $cpu0['ru_utime.tv_usec']) / 1000) +
            (($cpu1['ru_stime.tv_sec']  - $cpu0['ru_stime.tv_sec'])  * 1000) +
            (($cpu1['ru_stime.tv_usec'] - $cpu0['ru_stime.tv_usec']) / 1000);

        try {
            Log::channel('metrics')->info('', [
                'ts'      => now()->toIso8601String(),
                'route'   => $request->route()?->uri() ?? $request->path(),
                'method'  => $request->method(),
                'status'  => $response->getStatusCode(),
                'ms'      => round((microtime(true) - $start) * 1000, 1),
                'cpu_ms'  => round($cpuMs, 1),
                'mem_mb'  => round(memory_get_peak_usage(true) / 1048576, 1),
                'queries' => $queries,
                'db_ms'   => round($dbTime, 1),
                'actor'   => optional($request->user())->id,
            ]);
        } catch (\Throwable $e) {
            // La instrumentación nunca debe tumbar una petición.
        }

        return $response;
    }
}