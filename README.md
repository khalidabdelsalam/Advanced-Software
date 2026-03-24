# AIOps Observability Lab 1

This repository implements Lab 1: AIOps Observability as requested.

## Summary
- Laravel API with endpoints: `/api/normal`, `/api/slow`, `/api/error`, `/api/random`, `/api/db`, `/api/validate`
- Telemetry middleware with correlation IDs, latency, enriched structured logs
- Centralized error categorization in `app/Exceptions/Handler.php`
- Prometheus metrics at `/metrics` (counters + histogram)
- Docker setup included: `docker-compose.yml`, `prometheus.yml`
- Grafana dashboard export: `grafana/aiops-dashboard.json`
- Traffic generator: `traffic_generator.py` + `ground_truth.json`
- Logs export template: `logs.json`

## Prerequisites
- Docker Desktop (for full stack run)
- PHP 8.1+, Composer
- Node/npm (for Laravel frontend if needed)

## Install and run
1. `docker-compose up -d`
2. Start Laravel app (from `laravel-app` folder):
   - `composer install`
   - `cp .env.example .env`
   - configure DB in .env
   - `php artisan key:generate`
   - `php artisan migrate --seed`
3. Grafana should be available at `http://localhost:3000`
4. Prometheus at `http://localhost:9090`

## Traffic generator
`python traffic_generator.py`

## Lab2 Detection Engine

### Command
`php artisan aiops:detect`

### Behavior
- Polls Prometheus every ~25s
- Computes baseline and detects latency/error/traffic anomalies
- Correlates incidents and writes to `storage/aiops/incidents.json`
- Emits alerts to console + `storage/aiops/alerts.json`

## Notes
- No PHP or Composer installed in this environment, so you must run on a proper dev machine.
