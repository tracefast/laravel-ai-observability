<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exceptions\PayloadTooLargeException;
use Tracefast\LaravelAiObservability\Support\Arr;
use Tracefast\LaravelAiObservability\Support\CircuitBreaker;
use Tracefast\LaravelAiObservability\Support\PackageInfo;

final class OtlpExporter implements Exporter
{
    private readonly OtlpEndpoint $endpoint;

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly ?int $maxPayloadBytes;

    private readonly int $retryAttempts;

    private readonly int $retryDelayMs;

    private readonly string $compression;

    private readonly CircuitBreaker $circuitBreaker;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config)
    {
        $this->endpoint = OtlpEndpoint::fromConfig($config);
        $this->timeout = $this->timeout($config['timeout'] ?? null);
        $this->connectTimeout = $this->connectTimeout($config['connect_timeout'] ?? null);
        $this->maxPayloadBytes = $this->maxPayloadBytes($config['max_payload_bytes'] ?? null);
        $this->retryAttempts = $this->retryAttempts($config['retry_attempts'] ?? null);
        $this->retryDelayMs = $this->retryDelayMs($config['retry_delay_ms'] ?? null);
        $this->compression = $this->compression($config['compression'] ?? null);
        $this->circuitBreaker = CircuitBreaker::fromConfig($this->circuitBreakerConfig($config['circuit_breaker'] ?? null));
    }

    public function export(Trace $trace): void
    {
        if (! $this->circuitBreaker->allowsRequest()) {
            return;
        }

        try {
            $payload = $this->payload($trace);
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $payloadBytes = strlen($json);

            if ($this->maxPayloadBytes !== null && $payloadBytes > $this->maxPayloadBytes) {
                report(new PayloadTooLargeException($payloadBytes, $this->maxPayloadBytes));

                return;
            }

            $headers = array_merge($this->endpoint->headers, [
                'Content-Type' => 'application/json',
            ]);

            $request = Http::timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry($this->retryAttempts + 1, $this->retryDelayMs, fn (Throwable $throwable): bool => $this->shouldRetry($throwable), throw: false)
                ->withHeaders($headers);

            $compressed = $this->shouldCompress() ? gzencode($json) : false;

            $response = is_string($compressed)
                ? $request
                    ->withHeaders([
                        'Content-Encoding' => 'gzip',
                    ])
                    ->withBody($compressed, 'application/json')
                    ->post($this->endpoint->url)
                : $request
                    ->post($this->endpoint->url, $payload);

            $response->throw();
            $this->circuitBreaker->recordSuccess();
        } catch (Throwable $throwable) {
            $this->circuitBreaker->recordFailure();
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
                            ...$this->resourceAttributes(),
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
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    private function resourceAttributes(): array
    {
        return [
            [
                'key' => 'service.name',
                'value' => ['stringValue' => (string) config('app.name', 'Laravel')],
            ],
            [
                'key' => 'telemetry.sdk.name',
                'value' => ['stringValue' => 'tracefast.laravel-ai-observability'],
            ],
            [
                'key' => 'telemetry.sdk.language',
                'value' => ['stringValue' => 'php'],
            ],
            [
                'key' => 'telemetry.sdk.version',
                'value' => ['stringValue' => PackageInfo::packageVersion()],
            ],
            [
                'key' => 'openinference.schema.version',
                'value' => ['stringValue' => PackageInfo::OpenInferenceSchemaVersion],
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

    private function connectTimeout(mixed $timeout): float
    {
        if (is_numeric($timeout) && (float) $timeout > 0) {
            return (float) $timeout;
        }

        return (float) config('ai-observability.export.connect_timeout', 0.5);
    }

    private function maxPayloadBytes(mixed $maxPayloadBytes): ?int
    {
        if (is_numeric($maxPayloadBytes)) {
            $value = (int) $maxPayloadBytes;

            return $value > 0 ? $value : null;
        }

        $value = (int) config('ai-observability.export.max_payload_bytes', 1048576);

        return $value > 0 ? $value : null;
    }

    private function retryAttempts(mixed $attempts): int
    {
        if (is_numeric($attempts)) {
            return max(0, (int) $attempts);
        }

        return max(0, (int) config('ai-observability.export.retry_attempts', 1));
    }

    private function retryDelayMs(mixed $delay): int
    {
        if (is_numeric($delay)) {
            return max(0, (int) $delay);
        }

        return max(0, (int) config('ai-observability.export.retry_delay_ms', 100));
    }

    private function compression(mixed $compression): string
    {
        $compression = strtolower(trim((string) ($compression ?? config('ai-observability.export.compression', 'none'))));

        return $compression === 'gzip' ? 'gzip' : 'none';
    }

    /**
     * @return array<string, mixed>
     */
    private function circuitBreakerConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }

        $global = config('ai-observability.export.circuit_breaker', []);

        return is_array($global) ? $global : [];
    }

    private function shouldRetry(Throwable $throwable): bool
    {
        if (! $throwable instanceof RequestException || $throwable->response === null) {
            return true;
        }

        $status = $throwable->response->status();

        return $status === 408
            || $status === 425
            || $status === 429
            || $status >= 500;
    }

    private function shouldCompress(): bool
    {
        return $this->compression === 'gzip' && function_exists('gzencode');
    }
}
