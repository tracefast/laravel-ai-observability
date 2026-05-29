<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Registry;

use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\Trace;

final class InMemoryTraceRegistry implements TraceRegistry
{
    /**
     * @var array<string, Trace>
     */
    private array $traces = [];

    /**
     * @var array<string, Span>
     */
    private array $rootSpans = [];

    public function start(string $invocationId, Trace $trace, Span $rootSpan): void
    {
        $trace->addSpan($rootSpan);

        $this->traces[$invocationId] = $trace;
        $this->rootSpans[$invocationId] = $rootSpan;
    }

    public function trace(string $invocationId): ?Trace
    {
        return $this->traces[$invocationId] ?? null;
    }

    public function rootSpan(string $invocationId): ?Span
    {
        return $this->rootSpans[$invocationId] ?? null;
    }

    public function forget(string $invocationId): void
    {
        unset($this->traces[$invocationId], $this->rootSpans[$invocationId]);
    }
}
