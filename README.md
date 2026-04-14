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
- Python 3.10+ with `pandas` and `matplotlib` for Lab 4 RCA

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

## Lab4 Root Cause Analysis

### Command
`python lab4_root_cause_analysis.py`

### Behavior
- Selects a detected anomaly window from `anomaly_predictions.csv`
- Analyzes latency, request rate, error rate, endpoint activity, and error categories
- Determines the most likely source endpoint for the incident
- Generates structured RCA output in `rca_report.json`
- Creates timeline visualization in `lab4_incident_timeline.png`
- Produces a narrative report in `lab4_rca_report.md`

## Lab5 Automated Incident Response

### Command
`php artisan aiops:respond`

### Behavior
- Monitors `storage/aiops/incidents.json`
- Applies response policies from `config/aiops_responses.php`
- Simulates actions (restart, scale, alert, throttle) and logs to `storage/aiops/responses.json`
- Escalates to `CRITICAL_ALERT` on failed action or persistent anomalies

## Notes
- No PHP or Composer installed in this environment, so you must run on a proper dev machine.
