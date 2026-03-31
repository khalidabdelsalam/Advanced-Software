<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AIOpsRespond extends Command
{
    protected $signature = 'aiops:respond';
    protected $description = 'Automated incident response engine';

    protected $incidentFile = 'storage/aiops/incidents.json';
    protected $responseFile = 'storage/aiops/responses.json';

    public function handle()
    {
        $this->info('Starting AIOps response engine...');

        if (!file_exists(storage_path('aiops'))) {
            mkdir(storage_path('aiops'), 0755, true);
        }

        if (!file_exists(base_path($this->incidentFile))) {
            file_put_contents(base_path($this->incidentFile), json_encode([]));
        }

        if (!file_exists(base_path($this->responseFile))) {
            file_put_contents(base_path($this->responseFile), json_encode([]));
        }

        while (true) {
            try {
                $this->runIteration();
            } catch (\Throwable $e) {
                $this->error('Response iteration failed: '.$e->getMessage());
            }

            sleep(20);
        }

        return 0;
    }

    protected function runIteration()
    {
        $incidents = $this->loadIncidents();
        if (empty($incidents)) {
            $this->line('No incidents detected.');
            return;
        }

        $policies = config('aiops_responses.policies', []);
        $successRate = (int) config('aiops_responses.simulation.success_rate', 80);
        $persistenceThreshold = (int) config('aiops_responses.simulation.persistence_threshold', 2);

        foreach ($incidents as $index => $incident) {
            $status = $incident['status'] ?? 'open';
            if (!in_array($status, ['open', 'mitigating'], true)) {
                continue;
            }

            $type = $incident['incident_type'] ?? 'UNKNOWN';
            $policy = $policies[$type] ?? [
                'action' => 'SEND_ALERT',
                'notes' => 'Default policy applied due to unknown incident type.',
            ];

            $attempts = (int) ($incident['response_attempts'] ?? 0);
            $lastResult = $incident['last_response_result'] ?? null;
            $persistenceChecks = (int) ($incident['persistence_checks'] ?? 0);

            if ($lastResult === 'success') {
                $persistenceChecks++;
                $incident['persistence_checks'] = $persistenceChecks;

                if ($persistenceChecks >= $persistenceThreshold) {
                    $this->escalateIncident($incident, $index, $incidents, 'Anomaly persists after automated action.');
                } else {
                    $incidents[$index] = $incident;
                }

                continue;
            }

            $action = $policy['action'];
            $notes = $policy['notes'] ?? '';
            $result = $this->simulateAction($successRate);

            $this->logResponse($incident['incident_id'] ?? 'unknown', $action, $result, $notes);

            $attempts++;
            $incident['response_attempts'] = $attempts;
            $incident['last_response_at'] = now()->toIso8601String();
            $incident['last_response_result'] = $result;
            $incident['status'] = $result === 'success' ? 'mitigating' : 'open';
            $incidents[$index] = $incident;

            if ($result === 'failed') {
                $this->escalateIncident($incident, $index, $incidents, 'Automated action failed.');
            }
        }

        $this->saveIncidents($incidents);
    }

    protected function simulateAction(int $successRate)
    {
        $roll = mt_rand(1, 100);
        return $roll <= $successRate ? 'success' : 'failed';
    }

    protected function escalateIncident(array $incident, int $index, array &$incidents, string $reason)
    {
        $action = 'CRITICAL_ALERT';
        $notes = 'Escalation triggered: '.$reason;

        $this->logResponse($incident['incident_id'] ?? 'unknown', $action, 'escalated', $notes);

        $incident['status'] = 'escalated';
        $incident['escalated_at'] = now()->toIso8601String();
        $incident['escalation_reason'] = $reason;
        $incidents[$index] = $incident;
    }

    protected function loadIncidents()
    {
        $file = base_path($this->incidentFile);
        if (!file_exists($file)) {
            return [];
        }

        $content = json_decode(file_get_contents($file), true);
        return is_array($content) ? $content : [];
    }

    protected function saveIncidents(array $incidents)
    {
        file_put_contents(base_path($this->incidentFile), json_encode($incidents, JSON_PRETTY_PRINT));
    }

    protected function logResponse(string $incidentId, string $action, string $result, string $notes = '')
    {
        $file = base_path($this->responseFile);
        $entries = [];
        if (file_exists($file)) {
            $entries = json_decode(file_get_contents($file), true) ?: [];
        }

        $entries[] = [
            'incident_id' => $incidentId,
            'action_taken' => $action,
            'timestamp' => now()->toIso8601String(),
            'result' => $result,
            'notes' => $notes,
        ];

        file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT));
    }
}
