<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

use Tracefast\LaravelAiObservability\Support\Clock;

final class Span
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId,
        private readonly string $name,
        private readonly SpanKind $kind,
        private SpanStatus $status = SpanStatus::Unset,
        private string $startedAt = '',
        private ?string $endedAt = null,
        private readonly array $attributes = [],
        private readonly mixed $input = null,
        private readonly mixed $output = null,
        private readonly ?string $errorType = null,
        private readonly ?string $errorMessage = null,
    ) {
        if ($this->startedAt === '') {
            $this->startedAt = Clock::now();
        }
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
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_ms' => Clock::durationMs($this->startedAt, $this->endedAt),
            'attributes' => array_merge($this->attributes, [
                'openinference.span.kind' => $this->kind->value,
            ]),
            'input' => $this->input,
            'output' => $this->output,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
        ];
    }
}
