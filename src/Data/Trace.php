<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

use Tracefast\LaravelAiObservability\Support\Arr;
use Tracefast\LaravelAiObservability\Support\Clock;

final class Trace
{
    /**
     * @var list<Span>
     */
    private array $spans = [];

    public function __construct(
        private readonly string $traceId,
        private readonly string $name,
        private string $startedAt = '',
        private SpanStatus $status = SpanStatus::Unset,
        private ?string $endedAt = null,
    ) {
        if ($this->startedAt === '') {
            $this->startedAt = Clock::now();
        }
    }

    public function addSpan(Span $span): self
    {
        $this->spans[] = $span;

        return $this;
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $trace = new self(
            traceId: (string) ($payload['trace_id'] ?? ''),
            name: (string) ($payload['name'] ?? 'trace'),
            startedAt: (string) ($payload['started_at'] ?? ''),
            status: SpanStatus::tryFrom((string) ($payload['status'] ?? '')) ?? SpanStatus::Unset,
            endedAt: isset($payload['ended_at']) ? (string) $payload['ended_at'] : null,
        );

        foreach (($payload['spans'] ?? []) as $span) {
            if (is_array($span)) {
                $trace->addSpan(Span::fromArray($span));
            }
        }

        return $trace;
    }

    /**
     * @return list<Span>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    public function finish(?string $endedAt = null, ?SpanStatus $status = null): self
    {
        $this->endedAt = $endedAt ?? Clock::now();
        $this->status = $status ?? $this->status;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Arr::withoutNulls([
            'trace_id' => $this->traceId,
            'name' => $this->name,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_ms' => Clock::durationMs($this->startedAt, $this->endedAt),
            'spans' => array_map(
                fn (Span $span): array => $span->toArray(),
                $this->spans,
            ),
        ]);
    }
}
