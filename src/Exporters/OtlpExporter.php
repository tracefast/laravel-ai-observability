<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Support\Arr;

final class OtlpExporter implements Exporter
{
    private readonly OtlpEndpoint $endpoint;

    private readonly float $timeout;

    private readonly ?string $preset;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->endpoint = OtlpEndpoint::fromConfig($config);
        $this->timeout = $this->timeout($config['timeout'] ?? null);
        $this->preset = $this->preset($config['preset'] ?? null);
    }

    public function export(Trace $trace): void
    {
        try {
            Http::timeout($this->timeout)
                ->withHeaders(array_merge($this->endpoint->headers, [
                    'Content-Type' => 'application/json',
                ]))
                ->post($this->endpoint->url, $this->payload($trace))
                ->throw();
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Trace $trace): array
    {
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => ['stringValue' => (string) config('app.name', 'Laravel')],
                            ],
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'tracefast.laravel-ai-observability',
                            ],
                            'spans' => array_map(
                                fn (Span $span): array => $this->spanPayload($span),
                                $trace->spans(),
                            ),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function spanPayload(Span $span): array
    {
        $payload = $span->toArray();

        return Arr::withoutNulls([
            'traceId' => $payload['trace_id'],
            'spanId' => $payload['span_id'],
            'parentSpanId' => $payload['parent_span_id'],
            'name' => $payload['name'],
            'startTimeUnixNano' => $this->unixNano($payload['started_at'] ?? null),
            'endTimeUnixNano' => $this->unixNano($payload['ended_at'] ?? null),
            'attributes' => $this->attributes($payload),
            'status' => $this->status($payload['status'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function attributes(array $payload): array
    {
        $attributes = [];
        $sourceAttributes = $payload['attributes'] ?? [];

        if (is_array($sourceAttributes)) {
            foreach ($sourceAttributes as $key => $value) {
                if (! is_string($key) || $value === null) {
                    continue;
                }

                $attributes[] = [
                    'key' => $key,
                    'value' => $this->attributeValue($value),
                ];
            }

            foreach ($this->presetAttributeAliases($sourceAttributes) as $key => $value) {
                $attributes[] = [
                    'key' => $key,
                    'value' => $this->attributeValue($value),
                ];
            }
        }

        foreach (['input' => 'input.value', 'output' => 'output.value'] as $source => $key) {
            if (! array_key_exists($source, $payload) || $payload[$source] === null) {
                continue;
            }

            $attributes[] = [
                'key' => $key,
                'value' => $this->attributeValue($payload[$source]),
            ];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributeValue(mixed $value): array
    {
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }

        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }

        if (is_float($value)) {
            return ['doubleValue' => $value];
        }

        if (is_string($value)) {
            return ['stringValue' => $value];
        }

        try {
            return ['stringValue' => json_encode($value, JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            return ['stringValue' => (string) $value];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function status(mixed $status): array
    {
        return match ($status) {
            SpanStatus::Ok->value => ['code' => 1],
            SpanStatus::Error->value => ['code' => 2],
            default => ['code' => 0],
        };
    }

    private function unixNano(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );

        $errors = DateTimeImmutable::getLastErrors();

        if ($dateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        $dateTime = $dateTime->setTimezone(new DateTimeZone('UTC'));

        return sprintf(
            '%d%06d000',
            $dateTime->getTimestamp(),
            (int) $dateTime->format('u'),
        );
    }

    private function timeout(mixed $timeout): float
    {
        if (is_numeric($timeout) && (float) $timeout > 0) {
            return (float) $timeout;
        }

        return (float) config('ai-observability.export.timeout', 2.0);
    }

    private function preset(mixed $preset): ?string
    {
        if (! is_string($preset) || trim($preset) === '') {
            return null;
        }

        return strtolower(trim($preset));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function presetAttributeAliases(array $attributes): array
    {
        if ($this->preset !== 'braintrust') {
            return [];
        }

        return Arr::withoutNulls([
            'braintrust.metadata.session_id' => $attributes['session.id'] ?? null,
            'braintrust.metadata.user_id' => $attributes['user.id'] ?? null,
            'braintrust.metadata.conversation_id' => $attributes['tracefast.ai.conversation_id'] ?? null,
        ]);
    }
}
