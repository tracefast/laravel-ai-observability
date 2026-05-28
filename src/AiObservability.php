<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Closure;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;

class AiObservability
{
    public function __construct(
        private readonly ExporterManager $exporters,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ai-observability.enabled', false);
    }

    public function exporter(?string $name = null): Exporter
    {
        return $this->exporters->exporter($name);
    }

    /**
     * @param  Closure(mixed ...): Exporter  $creator
     */
    public function extend(string $driver, Closure $creator): void
    {
        $this->exporters->extend($driver, $creator);
    }
}
