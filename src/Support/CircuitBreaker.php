<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

final class CircuitBreaker
{
    private int $failures = 0;

    private float $openedUntil = 0.0;

    public function __construct(
        private readonly bool $enabled,
        private readonly int $failureThreshold,
        private readonly int $openSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            failureThreshold: max(1, (int) ($config['failure_threshold'] ?? 3)),
            openSeconds: max(1, (int) ($config['open_seconds'] ?? 30)),
        );
    }

    public function allowsRequest(): bool
    {
        return ! $this->enabled || microtime(true) >= $this->openedUntil;
    }

    public function recordSuccess(): void
    {
        $this->failures = 0;
        $this->openedUntil = 0.0;
    }

    public function recordFailure(): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->failures++;

        if ($this->failures >= $this->failureThreshold) {
            $this->openedUntil = microtime(true) + $this->openSeconds;
        }
    }
}
