<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\AiObservability;

it('registers the package config and root service', function (): void {
    expect(config('ai-observability.default'))->toBe('stack')
        ->and(config('ai-observability.capture.content'))->toBe('full')
        ->and(app(AiObservability::class))->toBeInstanceOf(AiObservability::class);
});
