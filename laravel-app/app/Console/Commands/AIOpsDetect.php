<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PrometheusClient;
use Illuminate\Support\Str;

class AIOpsDetect extends Command
{
    protected $signature = 'aiops:detect';
    protected $description = 'Continuous AIOps detection loop using Prometheus metrics';
    protected $prom;

    protected $baselineFile = 'storage/aiops/baselines.json';
    protected $incidentFile = 'storage/aiops/incidents.json';
    protected $alertedIncidents = [];

    protected $endpoints = ['/api/normal', '/api/slow', '/api/db', '/api/error', '/api/validate'];

    public function __construct(PrometheusClient $prom)
    {
        parent::__construct();
        $this->prom = $prom;
    }

    public function handle()
    {
        $this->info('Starting AIOps detection engine...');

        if (!file_exists(storage_path('aiops'))) {
            mkdir(storage_path('aiops'), 0755, true);
        }

        if (!file_exists(base_path($this->incidentFile))) {
            file_put_contents(base_path($this->incidentFile), json_encode([]));
        }

        while (true) {
            try {
                $this->runIteration();
            } catch (\Throwable $e) {
                $this->error('Detection iteration failed: '.$e->getMessage());
            }

            sleep(25);
        }

        return 0;
    }

    protected function runIteration()
    {
        $requestRates = $this->prom->requestRateByEndpoint();
        $errorRates = $this->prom->errorRateByEndpoint();
        $latencies = $this->prom->latencyPercentilesByEndpoint();
        $errorCategories = $this->prom->errorCategoryCounters();

        $current = $this->normalizeMetrics($requestRates, $errorRates, $latencies, $errorCategories);
        $this->info('Current metrics: '.json_encode($current));

        $baseline = $this->loadBaseline();
        $baseline = $this->updateBaseline($baseline, $current);
        $this->saveBaseline($baseline);

        $anomalySignals = $this->detectAnomalies($baseline, $current);
        if (!empty($anomalySignals)) {
            $incident = $this->correlateIncident($baseline, $current, $anomalySignals);
            $this->recordIncident($incident);
            $this->emitAlert($incident);
        }

        return true;
    }

    protected function normalizeMetrics($requestRates, $errorRates, $latencies, $errorCategories)
    {
        $result = [];

        foreach ($this->endpoints as $endpoint) {
            $result[$endpoint] = [
                'request_rate' => 0.0,
                'error_rate' => 0.0,
                'latency_p95' => 0.0,
                'errors' => 0.0,
            ];
        }

        foreach ($requestRates as $row) {
            $path = $row['metric']['path'] ?? '/api/unknown';
            if (!isset($result[$path])) { continue; }
            $result[$path]['request_rate'] = (float)($row['value'][1] ?? 0);
        }

        foreach ($errorRates as $row) {
            $path = $row['metric']['path'] ?? '/api/unknown';
            if (!isset($result[$path])) { continue; }
            $result[$path]['error_rate'] = (float)($row['value'][1] ?? 0);
        }

        if (isset($latencies['p95'])) {
            foreach ($latencies['p95'] as $row) {
                $path = $row['metric']['path'] ?? '/api/unknown';
                if (!isset($result[$path])) { continue; }
                $result[$path]['latency_p95'] = (float)($row['value'][1] ?? 0);
            }
        }

        foreach ($errorCategories as $row) {
            $cat = $row['metric']['error_category'] ?? 'UNKNOWN';
            $result['/api/normal']['errors'] += (float)($row['value'][1] ?? 0); // global summary
            $result[$cat] = (float)($row['value'][1] ?? 0);
        }

        return $result;
    }

    protected function loadBaseline()
    {
        if (file_exists(base_path($this->baselineFile))) {
            $content = json_decode(file_get_contents(base_path($this->baselineFile)), true);
            return is_array($content) ? $content : [];
        }
        return [];
    }

    protected function saveBaseline($baseline)
    {
        file_put_contents(base_path($this->baselineFile), json_encode($baseline, JSON_PRETTY_PRINT));
    }

    protected function updateBaseline($baseline, $current)
    {
        foreach ($current as $endpoint => $stats) {
            if (!isset($baseline[$endpoint])) {
                $baseline[$endpoint] = [
                    'avg_latency_p95' => $stats['latency_p95'],
                    'avg_request_rate' => $stats['request_rate'],
                    'avg_error_rate' => $stats['error_rate'],
                    'count' => 1,
                ];
                continue;
            }

            $count = max(1, $baseline[$endpoint]['count']);
            $baseline[$endpoint]['avg_latency_p95'] = ($baseline[$endpoint]['avg_latency_p95'] * $count + $stats['latency_p95']) / ($count + 1);
            $baseline[$endpoint]['avg_request_rate'] = ($baseline[$endpoint]['avg_request_rate'] * $count + $stats['request_rate']) / ($count + 1);
            $baseline[$endpoint]['avg_error_rate'] = ($baseline[$endpoint]['avg_error_rate'] * $count + $stats['error_rate']) / ($count + 1);
            $baseline[$endpoint]['count'] = $count + 1;
        }

        return $baseline;
    }

    protected function detectAnomalies($baseline, $current)
    {
        $anomalies = [];
        foreach ($current as $endpoint => $stats) {
            if (!isset($baseline[$endpoint])) { continue; }
            $b = $baseline[$endpoint];

            if ($b['avg_latency_p95'] > 0 && $stats['latency_p95'] > 3 * $b['avg_latency_p95']) {
                $anomalies[] = ['endpoint' => $endpoint, 'signal' => 'LATENCY_SPIKE', 'value' => $stats['latency_p95'], 'baseline' => $b['avg_latency_p95']];
            }

            if ($b['avg_error_rate'] >= 0 && $stats['error_rate'] > 0.1) {
                $anomalies[] = ['endpoint' => $endpoint, 'signal' => 'ERROR_RATE_HIGH', 'value' => $stats['error_rate'], 'baseline' => $b['avg_error_rate']];
            }

            if ($b['avg_request_rate'] > 0 && $stats['request_rate'] > 2 * $b['avg_request_rate']) {
                $anomalies[] = ['endpoint' => $endpoint, 'signal' => 'TRAFFIC_SURGE', 'value' => $stats['request_rate'], 'baseline' => $b['avg_request_rate']];
            }
        }

        return $anomalies;
    }

    protected function correlateIncident($baseline, $current, $signals)
    {
        $types = array_column($signals, 'signal');
        $endpoint = $signals[0]['endpoint'] ?? 'unknown';

        if (in_array('LATENCY_SPIKE', $types) && in_array('ERROR_RATE_HIGH', $types)) {
            $type = 'SERVICE_DEGRADATION';
            $severity = 'critical';
        } elseif (in_array('ERROR_RATE_HIGH', $types)) {
            $type = 'ERROR_STORM';
            $severity = 'major';
        } elseif (in_array('LATENCY_SPIKE', $types)) {
            $type = 'LATENCY_SPIKE';
            $severity = 'major';
        } elseif (in_array('TRAFFIC_SURGE', $types)) {
            $type = 'TRAFFIC_SURGE';
            $severity = 'minor';
        } else {
            $type = 'LOCALIZED_ENDPOINT_FAILURE';
            $severity = 'minor';
        }

        $id = Str::uuid()->toString();
        return [
            'incident_id' => $id,
            'incident_type' => $type,
            'severity' => $severity,
            'status' => 'open',
            'detected_at' => now()->toIso8601String(),
            'affected_service' => 'aiops-api',
            'affected_endpoints' => array_unique(array_column($signals, 'endpoint')),
            'triggering_signals' => $signals,
            'baseline_values' => $baseline,
            'observed_values' => $current,
            'summary' => sprintf('%s detected on %d endpoints', $type, count($signals)),
        ];
    }

    protected function recordIncident($incident)
    {
        $file = base_path($this->incidentFile);
        $current = [];
        if (file_exists($file)) {
            $current = json_decode(file_get_contents($file), true) ?: [];
        }

        $current[] = $incident;
        file_put_contents($file, json_encode($current, JSON_PRETTY_PRINT));
    }

    protected function emitAlert($incident)
    {
        $key = $incident['incident_type'].'|'.implode(',', $incident['affected_endpoints']);
        if (in_array($key, $this->alertedIncidents)) {
            return;
        }

        $this->alertedIncidents[] = $key;

        $alert = [
            'incident_id' => $incident['incident_id'],
            'incident_type' => $incident['incident_type'],
            'severity' => $incident['severity'],
            'timestamp' => now()->toIso8601String(),
            'summary' => $incident['summary'],
        ];

        $this->line('ALERT: '.json_encode($alert));

        file_put_contents(storage_path('aiops/alerts.json'), json_encode(array_merge(
            (file_exists(storage_path('aiops/alerts.json')) ? json_decode(file_get_contents(storage_path('aiops/alerts.json')), true) : []),
            [$alert]
        ), JSON_PRETTY_PRINT));
    }
}
