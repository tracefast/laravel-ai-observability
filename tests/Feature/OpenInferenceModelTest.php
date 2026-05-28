<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Support\Arr;
use Tracefast\LaravelAiObservability\Support\Clock;
use Tracefast\LaravelAiObservability\Support\Ids;

it('serializes a trace with openinference span attributes', function (): void {
    $trace = new Trace(
        traceId: '1234567890abcdef1234567890abcdef',
        name: 'Agent run',
        startedAt: '2026-05-28T10:00:00.000000Z',
        status: SpanStatus::Ok,
    );

    $trace->addSpan(new Span(
        traceId: '1234567890abcdef1234567890abcdef',
        spanId: '1234567890abcdef',
        parentSpanId: null,
        name: 'Agent span',
        kind: SpanKind::Agent,
        status: SpanStatus::Ok,
        startedAt: '2026-05-28T10:00:00.000000Z',
        endedAt: '2026-05-28T10:00:01.000000Z',
        attributes: [
            'openinference.span.kind' => 'agent',
            'tracefast.ai.invocation_id' => 'invocation-123',
        ],
        input: ['prompt' => 'Hello'],
        output: ['text' => 'Hi'],
    ));

    $trace->finish('2026-05-28T10:00:01.000000Z', SpanStatus::Ok);

    $serialized = $trace->toArray();

    expect($serialized)->toMatchArray([
        'trace_id' => '1234567890abcdef1234567890abcdef',
        'name' => 'Agent run',
        'status' => 'ok',
        'duration_ms' => 1000.0,
    ])
        ->and($serialized['spans'][0]['span_id'])->toBe('1234567890abcdef')
        ->and($serialized['spans'][0]['kind'])->toBe('agent')
        ->and($serialized['spans'][0]['attributes'])->toMatchArray([
            'openinference.span.kind' => 'agent',
            'tracefast.ai.invocation_id' => 'invocation-123',
        ]);
});

it('provides trace model support helpers', function (): void {
    expect(Ids::traceId())->toMatch('/^[a-f0-9]{32}$/')
        ->and(Ids::spanId())->toMatch('/^[a-f0-9]{16}$/')
        ->and(Clock::now())->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and(Clock::durationMs(
            '2026-05-28T10:00:00.000000Z',
            '2026-05-28T10:00:01.234567Z',
        ))->toBe(1234.567)
        ->and(Clock::durationMs('invalid', '2026-05-28T10:00:01.234567Z'))->toBeNull()
        ->and(Arr::withoutNulls([
            'kept' => 'value',
            'missing' => null,
            'zero' => 0,
        ]))->toBe([
            'kept' => 'value',
            'zero' => 0,
        ]);
});
