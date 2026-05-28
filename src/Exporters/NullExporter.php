<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

final class NullExporter implements Exporter
{
    public function export(Trace $trace): void
    {
        //
    }
}
