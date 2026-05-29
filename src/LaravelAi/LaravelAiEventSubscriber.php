<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

use Illuminate\Contracts\Events\Dispatcher;
use LogicException;
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
     * @var array<string, string>
     */
    private const Handlers = [
        'PromptingAgent' => 'handlePrompting',
        'StreamingAgent' => 'handlePrompting',
        'AgentPrompted' => 'handlePrompted',
        'AgentStreamed' => 'handlePrompted',
        'InvokingTool' => 'handleInvokingTool',
        'ToolInvoked' => 'handleToolInvoked',
    ];

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

        try {
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
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function handleInvokingTool(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        try {
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
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function handleToolInvoked(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        try {
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
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function handlePrompted(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $invocationId = $this->invocationIdFromEvent($event);

        try {
            $payload = $this->mapper->prompted($event);
            $invocationId = $this->invocationId($payload['invocation_id']) ?? $invocationId;

            if ($invocationId === null) {
                return;
            }

            $trace = $this->registry->trace($invocationId);
            $rootSpan = $this->registry->rootSpan($invocationId);

            if ($trace === null || $rootSpan === null) {
                return;
            }

            $rootSpan->withOutput($payload['output']);
            $rootSpan->withAttributes($payload['attributes']);
            $rootSpan->finish(status: SpanStatus::Ok);
            $trace->finish(status: SpanStatus::Ok);

            $this->observability->export($trace);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            if ($invocationId !== null) {
                $this->registry->forget($invocationId);
                $this->forgetToolSpans($invocationId);
            }
        }
    }

    private function handlerFor(string $event): string
    {
        $eventName = class_basename($event);

        return self::Handlers[$eventName]
            ?? throw new LogicException("No Laravel AI event subscriber handler is mapped for [{$eventName}].");
    }

    private function invocationId(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function invocationIdFromEvent(object $event): ?string
    {
        $properties = get_object_vars($event);

        foreach (['invocationId', 'invocation_id'] as $property) {
            if (array_key_exists($property, $properties)) {
                return is_string($properties[$property]) ? $this->invocationId($properties[$property]) : null;
            }
        }

        return null;
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
