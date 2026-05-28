<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Contracts;

use Tracefast\LaravelAiObservability\Data\Trace;

interface Exporter
{
    public function export(Trace $trace): void;
}
