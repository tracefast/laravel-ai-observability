<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exceptions\PayloadTooLargeException;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;
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
            'session.id' => 'candidate-coach-123',
            'user.id' => '42',
            'tracefast.ai.conversation_id' => 'candidate-coach-123',
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

it('parses configured otlp header strings for cached laravel config', function (): void {
    withOtlpEnvironment([], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([
            'header_string' => 'x-tracefast-api-key=secret%3Dvalue,Authorization=Bearer%20cached,x-empty=',
        ]);

        expect($endpoint->headers)->toBe([
            'x-tracefast-api-key' => 'secret=value',
            'Authorization' => 'Bearer cached',
        ]);
    });
});

it('merges configured header strings with tracefast api key config', function (): void {
    withOtlpEnvironment([], function (): void {
        $endpoint = OtlpEndpoint::fromConfig([
            'header_string' => 'Authorization=Bearer%20cached',
            'headers' => [
                'x-tracefast-api-key' => 'tracefast-key',
            ],
        ]);

        expect($endpoint->headers)->toBe([
            'Authorization' => 'Bearer cached',
            'x-tracefast-api-key' => 'tracefast-key',
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

it('sends configured otlp header strings', function (): void {
    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 200),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel/v1/traces',
        'header_string' => 'x-tracefast-api-key=tracefast-key',
    ]))->export(otlpTrace());

    Http::assertSent(function ($request): bool {
        expect($request->hasHeader('x-tracefast-api-key', 'tracefast-key'))->toBeTrue();

        return true;
    });
});

it('sends tracefast exporter payloads to tracefast with the project api key', function (): void {
    config()->set('ai-observability.exporters.tracefast', [
        'driver' => 'otlp',
        'preset' => 'tracefast',
        'endpoint' => 'https://collector.tracefast.test/v1/traces',
        'headers' => [
            'x-tracefast-api-key' => 'tracefast-key',
        ],
    ]);

    Http::fake([
        'https://collector.tracefast.test/v1/traces' => Http::response([], 200),
    ]);

    app(ExporterManager::class)->exporter('tracefast')->export(otlpTrace());

    Http::assertSent(function ($request): bool {
        expect($request->url())->toBe('https://collector.tracefast.test/v1/traces')
            ->and($request->hasHeader('x-tracefast-api-key', 'tracefast-key'))->toBeTrue();

        return true;
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
            ->and($payload['resourceSpans'][0]['resource']['attributes'])->toContain([
                'key' => 'telemetry.sdk.name',
                'value' => ['stringValue' => 'tracefast.laravel-ai-observability'],
            ])
            ->and($payload['resourceSpans'][0]['resource']['attributes'])->toContain([
                'key' => 'tracefast.platform',
                'value' => ['stringValue' => 'laravel-ai'],
            ])
            ->and($payload['resourceSpans'][0]['resource']['attributes'])->toContain([
                'key' => 'openinference.schema.version',
                'value' => ['stringValue' => '1.0.0'],
            ])
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
                'key' => 'session.id',
                'value' => ['stringValue' => 'candidate-coach-123'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'user.id',
                'value' => ['stringValue' => '42'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'tracefast.ai.conversation_id',
                'value' => ['stringValue' => 'candidate-coach-123'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'tracefast.ai.sdk.name',
                'value' => ['stringValue' => 'laravel/ai'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'tracefast.ai.sdk.version',
                'value' => ['stringValue' => 'v0.7.2'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'input.value',
                'value' => ['stringValue' => '{"messages":[{"role":"user","content":"Hello"}]}'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'input.mime_type',
                'value' => ['stringValue' => 'application/json'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'output.value',
                'value' => ['stringValue' => '{"content":"Hi there"}'],
            ])
            ->and($span['attributes'])->toContain([
                'key' => 'output.mime_type',
                'value' => ['stringValue' => 'application/json'],
            ]);

        return true;
    });
});

it('allows the tracefast platform resource attribute to be overridden', function (): void {
    config()->set('ai-observability.platform', 'custom-laravel-agent');

    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 200),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
    ]))->export(otlpTrace());

    Http::assertSent(function ($request): bool {
        expect($request->data()['resourceSpans'][0]['resource']['attributes'])->toContain([
            'key' => 'tracefast.platform',
            'value' => ['stringValue' => 'custom-laravel-agent'],
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

it('drops payloads that exceed the configured byte limit', function (): void {
    Exceptions::fake();
    Http::fake();

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
        'max_payload_bytes' => 10,
    ]))->export(otlpTrace());

    Http::assertNothingSent();
    Exceptions::assertReported(PayloadTooLargeException::class);
});

it('gzip compresses otlp json payloads when enabled', function (): void {
    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 200),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
        'compression' => 'gzip',
    ]))->export(otlpTrace());

    Http::assertSent(function ($request): bool {
        expect($request->hasHeader('Content-Encoding', 'gzip'))->toBeTrue()
            ->and(json_decode(gzdecode($request->body()), true))->toHaveKey('resourceSpans.0.scopeSpans.0.spans.0');

        return true;
    });
});

it('retries transient otlp failures with a bounded retry count', function (): void {
    Http::fake([
        'https://example.test/otel/v1/traces' => Http::sequence()
            ->push([], 500)
            ->push([], 200),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
        'retry_attempts' => 1,
        'retry_delay_ms' => 0,
    ]))->export(otlpTrace());

    Http::assertSentCount(2);
});

it('opens a circuit breaker after repeated otlp failures', function (): void {
    Exceptions::fake();

    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 500),
    ]);

    $exporter = new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
        'retry_attempts' => 0,
        'circuit_breaker' => [
            'enabled' => true,
            'failure_threshold' => 1,
            'open_seconds' => 60,
        ],
    ]);

    $exporter->export(otlpTrace());
    $exporter->export(otlpTrace());

    Http::assertSentCount(1);
    Exceptions::assertReported(RequestException::class);
});

it('matches the golden openinference otlp payload fixture', function (): void {
    Http::fake([
        'https://example.test/otel/v1/traces' => Http::response([], 200),
    ]);

    (new OtlpExporter([
        'endpoint' => 'https://example.test/otel',
    ]))->export(otlpTrace());

    Http::assertSent(function ($request): bool {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/../Fixtures/otlp-openinference-golden.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($request->data())->toMatchArray($fixture);

        return true;
    });
});
