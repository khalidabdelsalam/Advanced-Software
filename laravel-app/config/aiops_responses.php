<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AIOps Automated Response Policies
    |--------------------------------------------------------------------------
    |
    | Map incident types to automated actions. Actions are simulated by the
    | aiops:respond command and written to storage/aiops/responses.json.
    |
    */
    'policies' => [
        'LATENCY_SPIKE' => [
            'action' => 'RESTART_SERVICE',
            'notes' => 'Restarting the affected service to recover latency.',
        ],
        'ERROR_STORM' => [
            'action' => 'SEND_ALERT',
            'notes' => 'Notifying on-call due to elevated error rates.',
        ],
        'TRAFFIC_SURGE' => [
            'action' => 'SCALE_SERVICE',
            'notes' => 'Scaling service instances to handle load.',
        ],
        'SERVICE_DEGRADATION' => [
            'action' => 'RESTART_SERVICE',
            'notes' => 'Restarting service as a first mitigation step.',
        ],
        'LOCALIZED_ENDPOINT_FAILURE' => [
            'action' => 'THROTTLE_TRAFFIC',
            'notes' => 'Throttling traffic on impacted endpoints.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Simulation Settings
    |--------------------------------------------------------------------------
    */
    'simulation' => [
        // Percent success rate for simulated actions.
        'success_rate' => 80,
        // Number of monitor cycles to wait before escalating a persistent incident.
        'persistence_threshold' => 2,
    ],
];
