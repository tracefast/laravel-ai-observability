<?php

return [
    'enabled' => env('AI_OBSERVABILITY_ENABLED', true),

    'default' => env('AI_OBSERVABILITY_EXPORTER', 'log'),

    'capture' => [
        'content' => env('AI_OBSERVABILITY_CAPTURE_CONTENT', 'full'),
    ],

    'export' => [
        'mode' => env('AI_OBSERVABILITY_EXPORT_MODE', 'defer'),
        'timeout' => (float) env('AI_OBSERVABILITY_EXPORT_TIMEOUT', 2.0),
    ],

    'exporters' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['log'],
        ],

        'log' => [
            'driver' => 'log',
            'channel' => env('AI_OBSERVABILITY_LOG_CHANNEL'),
            'level' => env('AI_OBSERVABILITY_LOG_LEVEL', 'debug'),
        ],

        'phoenix' => [
            'driver' => 'otlp',
            'preset' => 'phoenix',
            'endpoint' => env('PHOENIX_COLLECTOR_ENDPOINT', 'http://localhost:6006/v1/traces'),
            'headers' => [],
        ],

        'langfuse' => [
            'driver' => 'otlp',
            'preset' => 'langfuse',
            'endpoint' => env('LANGFUSE_OTEL_ENDPOINT'),
            'headers' => [
                'Authorization' => env('LANGFUSE_OTEL_AUTHORIZATION'),
                'x-langfuse-ingestion-version' => env('LANGFUSE_INGESTION_VERSION', '4'),
            ],
        ],

        'braintrust' => [
            'driver' => 'otlp',
            'preset' => 'braintrust',
            'endpoint' => env('BRAINTRUST_OTEL_ENDPOINT', 'https://api.braintrust.dev/otel/v1/traces'),
            'headers' => [
                'Authorization' => env('BRAINTRUST_API_KEY') ? 'Bearer '.env('BRAINTRUST_API_KEY') : null,
                'x-bt-parent' => env('BRAINTRUST_PARENT'),
            ],
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('AI_OBSERVABILITY_DB_CONNECTION'),
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
