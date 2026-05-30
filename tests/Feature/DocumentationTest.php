<?php

declare(strict_types=1);

it('documents the simplified exporter configuration', function (): void {
    $readme = file_get_contents(getcwd().'/README.md');

    expect($readme)->toContain('Observability is enabled by default')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORTER=log')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORTER=phoenix,langfuse')
        ->and($readme)->toContain('PHOENIX_COLLECTOR_ENDPOINT=http://localhost:6006/v1/traces')
        ->and($readme)->toContain('LANGFUSE_OTEL_ENDPOINT=https://cloud.langfuse.com/api/public/otel/v1/traces')
        ->and($readme)->toContain('LANGFUSE_OTEL_AUTHORIZATION="Basic <base64-public-key-colon-secret-key>"')
        ->and($readme)->toContain('BRAINTRUST_API_KEY=<braintrust-api-key>')
        ->and($readme)->toContain('BRAINTRUST_PARENT=project_name:<project-name>')
        ->and($readme)->toContain('AI_OBSERVABILITY_CAPTURE_CONTENT=off')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORT_MODE=background')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORT_CONNECT_TIMEOUT=0.5')
        ->and($readme)->toContain('AI_OBSERVABILITY_MAX_PAYLOAD_BYTES=1048576')
        ->and($readme)->toContain('AI_OBSERVABILITY_SAMPLE_RATE=1.0')
        ->and($readme)->toContain('AI_OBSERVABILITY_OTLP_COMPRESSION=gzip')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORT_RETRY_ATTEMPTS=1')
        ->and($readme)->toContain('AI_OBSERVABILITY_CIRCUIT_BREAKER=true')
        ->and($readme)->toContain('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://collector.example.com/v1/traces')
        ->and($readme)->toContain('openinference.schema.version')
        ->and($readme)->toContain('tracefast.ai.sdk.version')
        ->and($readme)->toContain('AiObservability::withSession(');
});
