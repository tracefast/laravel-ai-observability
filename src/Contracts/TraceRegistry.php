<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Contracts;

use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\Trace;

interface TraceRegistry
{
    public function start(string $invocationId, Trace $trace, Span $rootSpan): void;

    public function trace(string $invocationId): ?Trace;

    public function rootSpan(string $invocationId): ?Span;

    public function forget(string $invocationId): void;
}
