<?php

declare(strict_types=1);

it('documents the simplified exporter configuration', function (): void {
    $readme = file_get_contents(getcwd().'/README.md');

    expect($readme)->toContain('Observability is enabled by default')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORTER=log')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORTER=phoenix,langfuse')
        ->and($readme)->toContain('BRAINTRUST_API_KEY=<braintrust-api-key>')
        ->and($readme)->toContain('AI_OBSERVABILITY_CAPTURE_CONTENT=off')
        ->and($readme)->toContain('AiObservability::withSession(');
});
