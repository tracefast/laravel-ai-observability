<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Tracefast\LaravelAiObservability\AiObservability;
use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Support\Clock;
use Tracefast\LaravelAiObservability\Support\Ids;

final class LaravelAiEventSubscriber
{
    /**
     * @var array<string, Span>
     */
    private array $toolSpans = [];

    public function __construct(
        private readonly AiObservability $observability,
        private readonly TraceRegistry $registry,
        private readonly LaravelAiEventMapper $mapper,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        foreach (EventClassMap::events() as $event) {
            $events->listen($event, [self::class, $this->handlerFor($event)]);
        }
    }

    public function handlePrompting(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $payload = $this->mapper->prompting($event);
        $invocationId = $this->invocationId($payload['invocation_id']);

        if ($invocationId === null) {
            return;
        }

        $now = Clock::now();
        $traceId = Ids::traceId();
        $rootSpan = new Span(
            traceId: $traceId,
            spanId: Ids::spanId(),
            parentSpanId: null,
            name: $payload['name'],
            kind: SpanKind::Agent,
            startedAt: $now,
            attributes: $payload['attributes'],
            input: $payload['input'],
        );

        $this->registry->start(
            $invocationId,
            new Trace(traceId: $traceId, name: $payload['name'], startedAt: $now),
            $rootSpan,
        );
    }

    public function handleInvokingTool(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $payload = $this->mapper->invokingTool($event);
        $invocationId = $this->invocationId($payload['invocation_id']);
        $toolInvocationId = $this->invocationId($payload['tool_invocation_id']);

        if ($invocationId === null || $toolInvocationId === null) {
            return;
        }

        $trace = $this->registry->trace($invocationId);
        $rootSpan = $this->registry->rootSpan($invocationId);

        if ($trace === null || $rootSpan === null) {
            return;
        }

        $toolSpan = new Span(
            traceId: $trace->traceId(),
            spanId: Ids::spanId(),
            parentSpanId: $rootSpan->spanId(),
            name: $payload['name'],
            kind: SpanKind::Tool,
            attributes: $payload['attributes'],
            input: $payload['input'],
        );

        $trace->addSpan($toolSpan);
        $this->toolSpans[$this->toolKey($invocationId, $toolInvocationId)] = $toolSpan;
    }

    public function handleToolInvoked(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $payload = $this->mapper->toolInvoked($event);
        $invocationId = $this->invocationId($payload['invocation_id']);
        $toolInvocationId = $this->invocationId($payload['tool_invocation_id']);

        if ($invocationId === null || $toolInvocationId === null) {
            return;
        }

        $toolSpan = $this->toolSpans[$this->toolKey($invocationId, $toolInvocationId)] ?? null;

        if ($toolSpan === null) {
            return;
        }

        $toolSpan->withOutput($payload['output']);
        $toolSpan->finish(status: SpanStatus::Ok);
    }

    public function handlePrompted(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $payload = $this->mapper->prompted($event);
        $invocationId = $this->invocationId($payload['invocation_id']);

        if ($invocationId === null) {
            return;
        }

        $trace = $this->registry->trace($invocationId);
        $rootSpan = $this->registry->rootSpan($invocationId);

        if ($trace === null || $rootSpan === null) {
            $this->registry->forget($invocationId);

            return;
        }

        try {
            $rootSpan->withOutput($payload['output']);
            $rootSpan->withAttributes($payload['attributes']);
            $rootSpan->finish(status: SpanStatus::Ok);
            $trace->finish(status: SpanStatus::Ok);

            $this->observability->exporter()->export($trace);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $this->registry->forget($invocationId);
            $this->forgetToolSpans($invocationId);
        }
    }

    private function handlerFor(string $event): string
    {
        return match (class_basename($event)) {
            'PromptingAgent', 'StreamingAgent' => 'handlePrompting',
            'AgentPrompted', 'AgentStreamed' => 'handlePrompted',
            'InvokingTool' => 'handleInvokingTool',
            'ToolInvoked' => 'handleToolInvoked',
        };
    }

    private function invocationId(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function toolKey(string $invocationId, string $toolInvocationId): string
    {
        return "{$invocationId}:{$toolInvocationId}";
    }

    private function forgetToolSpans(string $invocationId): void
    {
        foreach (array_keys($this->toolSpans) as $key) {
            if (str_starts_with($key, "{$invocationId}:")) {
                unset($this->toolSpans[$key]);
            }
        }
    }
}
