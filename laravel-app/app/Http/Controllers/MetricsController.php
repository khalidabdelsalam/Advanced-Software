<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

class MetricsController extends Controller
{
    public function metrics(Request $request)
    {
        $registry = app(CollectorRegistry::class);
        $renderer = new RenderTextFormat();

        return response($renderer->render($registry->getMetricFamilySamples()), 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }
}
