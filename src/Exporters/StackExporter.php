<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

final class StackExporter implements Exporter
{
    /**
     * @param  iterable<Exporter>  $exporters
     */
    public function __construct(
        private readonly iterable $exporters,
    ) {}

    public function export(Trace $trace): void
    {
        foreach ($this->exporters as $exporter) {
            try {
                $exporter->export($trace);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
