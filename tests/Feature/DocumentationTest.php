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
        ->and($readme)->toContain('BRAINTRUST_PARENT=project_name:nexxa')
        ->and($readme)->toContain('AI_OBSERVABILITY_CAPTURE_CONTENT=off')
        ->and($readme)->toContain('AiObservability::withSession(');
});
