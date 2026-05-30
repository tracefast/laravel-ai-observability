<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;

final class ExportTraceJob implements ShouldQueue
{
    public ?string $connection = null;

    public ?string $queue = null;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $trace
     */
    public function __construct(
        public array $trace,
        ?string $connection = null,
        ?string $queue = null,
    ) {
        $this->connection = $connection;
        $this->queue = $queue;
    }

    public function handle(ExporterManager $exporters): void
    {
        $exporters->exporter()->export(Trace::fromArray($this->trace));
    }
}
