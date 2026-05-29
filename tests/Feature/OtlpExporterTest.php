<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\OtlpEndpoint;
use Tracefast\LaravelAiObservability\Exporters\OtlpExporter;

function otlpTrace(): Trace
{
    $trace = new Trace(
        traceId: '0123456789abcdef0123456789abcdef',
        name: 'chat request',
        startedAt: '2026-01-01T00:00:00.123456Z',
    );

    $trace->addSpan((new Span(
        traceId: '0123456789abcdef0123456789abcdef',
        spanId: '0123456789abcdef',
        parentSpanId: 'fedcba9876543210',
        name: 'llm completion',
        kind: SpanKind::Llm,
        status: SpanStatus::Ok,
        startedAt: '2026-01-01T00:00:00.123456Z',
        attributes: [
            'llm.model_name' => 'gpt-4.1',
            'empty.value' => null,
        ],
        input: ['messages' => [['role' => 'user', 'content' => 'Hello']]],
        output: ['content' => 'Hi there'],
    ))->finish(
        endedAt: '2026-01-01T00:00:01.654321Z',
        status: SpanStatus::Ok,
    ));

    return $trace;
}

function withOtlpEnvironment(array $variables, Closure $callback): void
{
    $names = [
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT',
        'OTEL_EXPORTER_OTLP_ENDPOINT',
        'OTEL_EXPORTER_OTLP_TRACES_HEADERS',
        'OTEL_EXPORTER_OTLP_HEADERS',
    ];

    foreach ($names as $name) {
        putenv($name);
    }

    foreach ($variables as $name => $value) {
        putenv("{$name}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ($names as $name) {
            putenv($name);
        }
    }
}

it('normalizes phoenix base endpoints to the traces path', function (): void {
    withOtlpEnvironment([], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([
            'endpoint' => 'http://localhost:6006',
            'headers' => [],
        ]);

        expect($endpoint->url)->toBe('http://localhost:6006/v1/traces');
    });
});

it('prefers traces endpoint env over base endpoint env and preserves traces paths', function (): void {
    withOtlpEnvironment([
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'http://collector:4318/custom/v1/traces',
        'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://collector:4318',
    ], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([]);

        expect($endpoint->url)->toBe('http://collector:4318/custom/v1/traces');
    });
});

it('preserves traces endpoint env exactly without appending traces path', function (): void {
    withOtlpEnvironment([
        'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT' => 'http://collector:4318/custom',
    ], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([]);

        expect($endpoint->url)->toBe('http://collector:4318/custom');
    });
});

it('merges environment and configured headers while filtering empty values', function (): void {
    withOtlpEnvironment([
        'OTEL_EXPORTER_OTLP_HEADERS' => 'Authorization=Bearer env,x-empty=,x-env=base',
        'OTEL_EXPORTER_OTLP_TRACES_HEADERS' => 'Authorization=Bearer traces,x-trace=yes',
    ], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([
            'headers' => [
                'Authorization' => 'Bearer explicit',
                'x-config' => 'true',
                'x-null' => null,
                'x-empty' => '',
            ],
        ]);

        expect($endpoint->headers)->toBe([
            'Authorization' => 'Bearer explicit',
            'x-env' => 'base',
            'x-trace' => 'yes',
            'x-config' => 'true',
        ]);
    });
});

it('url decodes environment header names and values', function (): void {
    withOtlpEnvironment([
        'OTEL_EXPORTER_OTLP_HEADERS' => 'Authorization=Bearer%20env,x-api%2Dkey=secret%3Dvalue',
    ], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([
            'headers' => [
                'x-config' => 'literal%20value',
            ],
        ]);

        expect($endpoint->headers)->toBe([
            'Authorization' => 'Bearer env',
            'x-api-key' => 'secret=value',
            'x-config' => 'literal%20value',
        ]);
    });
});

it('sends otlp http json to the explicit endpoint with configured headers', function (): void {
    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 200),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel/v1/traces',
        'headers' => [
            'Authorization' => 'Bearer token',
        ],
        'timeout' => 4.5,
    ]))->export(otlpTrace());

    Http::assertSent(function ($request): bool {
        $payload = $request->data();
        $span = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

        expect($request->url())->toBe('https://example.test/otel/v1/traces')
            ->and($request->hasHeader('Content-Type', 'application/json'))->toBeTrue()
            ->and($request->hasHeader('Authorization', 'Bearer token'))->toBeTrue()
            ->and($payload)->toHaveKey('resourceSpans.0.scopeSpans.0.spans.0')
            ->and($span)->toMatchArray([
                'traceId' => '0123456789abcdef0123456789abcdef',
                'spanId' => '0123456789abcdef',
                'parentSpanId' => 'fedcba9876543210',
                'name' => 'llm completion',
                'startTimeUnixNano' => '1767225600123456000',
                'endTimeUnixNano' => '1767225601654321000',
                'status' => ['code' => 1],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'llm.model_name',
                'value' => ['stringValue' => 'gpt-4.1'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'input.value',
                'value' => ['stringValue' => '{"messages":[{"role":"user","content":"Hello"}]}'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'output.value',
                'value' => ['stringValue' => '{"content":"Hi there"}'],
            ]);

        return true;
    });
});

it('duplicates standard session attributes into braintrust metadata for the braintrust preset', function (): void {
    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 200),
    ]);

    $trace = new Trace(
        traceId: '0123456789abcdef0123456789abcdef',
        name: 'chat request',
        startedAt: '2026-01-01T00:00:00.123456Z',
    );

    $trace->addSpan((new Span(
        traceId: '0123456789abcdef0123456789abcdef',
        spanId: '0123456789abcdef',
        parentSpanId: null,
        name: 'llm completion',
        kind: SpanKind::Llm,
        status: SpanStatus::Ok,
        startedAt: '2026-01-01T00:00:00.123456Z',
        attributes: [
            'session.id' => 'candidate-coach-123',
            'user.id' => '42',
            'tracefast.ai.conversation_id' => 'candidate-coach-123',
        ],
    ))->finish(
        endedAt: '2026-01-01T00:00:01.654321Z',
        status: SpanStatus::Ok,
    ));

    (new OtlpExporter([
        'preset' => 'braintrust',
        'endpoint' => 'https://example.test/otel/v1/traces',
    ]))->export($trace);

    Http::assertSent(function ($request): bool {
        $attributes = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'];

        expect($attributes)
            ->toContain([
                'key' => 'session.id',
                'value' => ['stringValue' => 'candidate-coach-123'],
            ])
            ->toContain([
                'key' => 'braintrust.metadata.session_id',
                'value' => ['stringValue' => 'candidate-coach-123'],
            ])
            ->toContain([
                'key' => 'braintrust.metadata.user_id',
                'value' => ['stringValue' => '42'],
            ])
            ->toContain([
                'key' => 'braintrust.metadata.conversation_id',
                'value' => ['stringValue' => 'candidate-coach-123'],
            ]);

        return true;
    });
});

it('reports non successful otlp http responses without throwing into application code', function (): void {
    Exceptions::fake();

    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 500),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
    ]))->export(otlpTrace());

    Http::assertSent(fn ($request): bool => $request->url() === 'https://example.test/otel/v1/traces');
    Exceptions::assertReported(RequestException::class);
});

it('omits nullable span fields and invalid timestamps from otlp payloads', function (): void {
    Http::fake([
        'http://localhost:4318/v1/traces' => Http::response([], 200),
    ]);

    $trace = new Trace(
        traceId: 'trace-invalid-time',
        name: 'invalid time',
        startedAt: 'invalid',
    );

    $trace->addSpan(new Span(
        traceId: 'trace-invalid-time',
        spanId: 'span_without_parent',
        parentSpanId: null,
        name: 'unfinished span',
        kind: SpanKind::Tool,
        startedAt: 'invalid',
    ));

    (new OtlpExporter([]))->export($trace);

    Http::assertSent(function ($request): bool {
        $span = $request->data()['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

        expect($span)->not->toHaveKeys(['parentSpanId', 'startTimeUnixNano', 'endTimeUnixNano']);

        return true;
    });
});

it('reports exporter failures without throwing into application code', function (): void {
    Http::fake(function (): void {
        throw new RuntimeException('collector unavailable');
    });

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
    ]))->export(otlpTrace());

    expect(true)->toBeTrue();
});
