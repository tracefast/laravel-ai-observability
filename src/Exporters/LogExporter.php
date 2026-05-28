<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Illuminate\Log\LogManager;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

final class LogExporter implements Exporter
{
    public function __construct(
        private readonly LogManager $log,
        private readonly ?string $channel,
        private readonly string $level,
    ) {}

    public function export(Trace $trace): void
    {
        $this->log->channel($this->channel)->log(
            $this->level,
            'ai-observability.trace.exported',
            ['trace' => $trace->toArray()],
        );
    }
}
