<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Prometheus\CollectorRegistry;

class TelemetryMiddleware
{
    protected $registry;

    public function __construct(CollectorRegistry $registry)
    {
        $this->registry = $registry;
    }

    public function handle(Request $request, Closure $next)
    {
        $start = microtime(true);

        $correlationId = $request->header('X-Request-Id', Str::uuid()->toString());
        $request->headers->set('X-Request-Id', $correlationId);

        $response = null;
        $exception = null;

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $exception = $e;
            // let exception handler process
            throw $e;
        } finally {
            $end = microtime(true);
            $latencyMs = round(($end - $start) * 1000, 2);

            $status = $response ? $response->getStatusCode() : ($exception ? 500 : 200);
            $errorCategory = 'SYSTEM_ERROR';
            if ($status >= 400 && $status < 500) {
                $errorCategory = 'VALIDATION_ERROR';
            } elseif ($status >= 500) {
                $errorCategory = 'DATABASE_ERROR';
            }

            // Timeout override for hard-slow mode
            if ($latencyMs > 4000) {
                $errorCategory = 'TIMEOUT_ERROR';
            }

            $payloadSize = strlen($request->getContent() ?? '');
            $responseSize = $response ? strlen($response->getContent() ?? '') : null;

            // Prometheus metrics
            $pathTag = $request->route() ? $request->route()->uri() : $request->path();
            $methodTag = $request->method();

            $requestCounter = $this->registry->getOrRegisterCounter('app', 'http_requests_total', 'Total HTTP requests', ['method', 'path', 'status']);
            $errorCounter = $this->registry->getOrRegisterCounter('app', 'http_errors_total', 'HTTP error requests by category', ['method', 'path', 'error_category']);
            $latencyHistogram = $this->registry->getOrRegisterHistogram('app', 'http_request_duration_seconds', 'HTTP request duration', ['method', 'path'], [0.05,0.1,0.25,0.5,1,2.5,5,10]);

            $requestCounter->inc([$methodTag, $pathTag, (string)$status]);
            if ($status >= 400) {
                $errorCounter->inc([$methodTag, $pathTag, $errorCategory]);
            }
            $latencyHistogram->observe($latencyMs / 1000.0, [$methodTag, $pathTag]);

            $log = [
                'timestamp' => now()->toIso8601String(),
                'correlation_id' => $correlationId,
                'method' => $methodTag,
                'path' => $request->path(),
                'status_code' => $status,
                'error_category' => $errorCategory,
                'severity' => $status >= 500 ? 'error' : 'info',
                'latency_ms' => $latencyMs,
                'client_ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent', null),
                'query' => $request->getQueryString() ?: null,
                'payload_size_bytes' => $payloadSize,
                'response_size_bytes' => $responseSize,
                'route_name' => optional($request->route())->getName() ?? 'unknown',
                'build_version' => env('BUILD_VERSION', 'unknown'),
                'host' => gethostname(),
            ];

            Log::channel('aiops')->info('http_request', $log);

            if ($response) {
                $response->headers->set('X-Request-Id', $correlationId);
            }
        }

        return $response;
    }
}
