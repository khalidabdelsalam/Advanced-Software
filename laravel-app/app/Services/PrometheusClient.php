<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class PrometheusClient
{
    protected $client;
    protected $endpoint;

    public function __construct()
    {
        $this->endpoint = env('PROMETHEUS_URL', 'http://localhost:9090');
        $this->client = new Client(['base_uri' => $this->endpoint, 'timeout' => 5]);
    }

    public function query(string $expr)
    {
        try {
            $res = $this->client->get('/api/v1/query', ['query' => ['query' => $expr]]);
            $body = json_decode($res->getBody()->getContents(), true);
            if (isset($body['status']) && $body['status'] === 'success') {
                return $body['data']['result'] ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Prometheus query failed', ['expr' => $expr, 'error' => $e->getMessage()]);
        }
        return [];
    }

    public function requestRateByEndpoint()
    {
        return $this->query('sum(rate(http_requests_total[1m])) by (path)');
    }

    public function errorRateByEndpoint()
    {
        return $this->query('sum(rate(http_errors_total[1m])) by (path)');
    }

    public function latencyPercentilesByEndpoint()
    {
        $p50 = $this->query('histogram_quantile(0.50, sum(rate(http_request_duration_seconds_bucket[5m])) by (le, path))');
        $p95 = $this->query('histogram_quantile(0.95, sum(rate(http_request_duration_seconds_bucket[5m])) by (le, path))');
        $p99 = $this->query('histogram_quantile(0.99, sum(rate(http_request_duration_seconds_bucket[5m])) by (le, path))');

        return ['p50' => $p50, 'p95' => $p95, 'p99' => $p99];
    }

    public function errorCategoryCounters()
    {
        return $this->query('sum(rate(http_errors_total[1m])) by (error_category)');
    }
}
