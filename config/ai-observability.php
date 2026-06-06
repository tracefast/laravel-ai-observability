<?php

return [
    'enabled' => env('AI_OBSERVABILITY_ENABLED', true),

    'default' => env('AI_OBSERVABILITY_EXPORTER', 'log'),

    'capture' => [
        'content' => env('AI_OBSERVABILITY_CAPTURE_CONTENT', 'full'),
    ],

    'export' => [
        'mode' => env('AI_OBSERVABILITY_EXPORT_MODE', 'defer'),
        'connection' => env('AI_OBSERVABILITY_EXPORT_CONNECTION'),
        'queue' => env('AI_OBSERVABILITY_EXPORT_QUEUE'),
        'sample_rate' => (float) env('AI_OBSERVABILITY_SAMPLE_RATE', 1.0),
        'timeout' => (float) env('AI_OBSERVABILITY_EXPORT_TIMEOUT', 2.0),
        'connect_timeout' => (float) env('AI_OBSERVABILITY_EXPORT_CONNECT_TIMEOUT', 0.5),
        'max_payload_bytes' => (int) env('AI_OBSERVABILITY_MAX_PAYLOAD_BYTES', 1048576),
        'retry_attempts' => (int) env('AI_OBSERVABILITY_EXPORT_RETRY_ATTEMPTS', 1),
        'retry_delay_ms' => (int) env('AI_OBSERVABILITY_EXPORT_RETRY_DELAY_MS', 100),
        'compression' => env('AI_OBSERVABILITY_OTLP_COMPRESSION', 'none'),
        'circuit_breaker' => [
            'enabled' => (bool) env('AI_OBSERVABILITY_CIRCUIT_BREAKER', false),
            'failure_threshold' => (int) env('AI_OBSERVABILITY_CIRCUIT_BREAKER_FAILURE_THRESHOLD', 3),
            'open_seconds' => (int) env('AI_OBSERVABILITY_CIRCUIT_BREAKER_OPEN_SECONDS', 30),
        ],
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

        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => env(
                'AI_OBSERVABILITY_OTLP_ENDPOINT',
                env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT')),
            ),
            'headers' => [],
            'header_string' => env(
                'AI_OBSERVABILITY_OTLP_HEADERS',
                env('OTEL_EXPORTER_OTLP_TRACES_HEADERS', env('OTEL_EXPORTER_OTLP_HEADERS')),
            ),
        ],

        'tracefast' => [
            'driver' => 'otlp',
            'preset' => 'tracefast',
            'endpoint' => env('TRACEFAST_OTEL_ENDPOINT', 'https://collector.tracefast.dev/v1/traces'),
            'headers' => [
                'x-tracefast-api-key' => env('TRACEFAST_API_KEY'),
            ],
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
