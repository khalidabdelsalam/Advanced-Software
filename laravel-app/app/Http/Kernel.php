<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    protected $middleware = [
        \App\Http\Middleware\TelemetryMiddleware::class,
    ];

    protected $middlewareGroups = [
        'web' => [],
        'api' => [
            'throttle:api',
            'bindings',
        ],
    ];

    protected $routeMiddleware = [
        'telemetry' => \App\Http\Middleware\TelemetryMiddleware::class,
    ];
}
