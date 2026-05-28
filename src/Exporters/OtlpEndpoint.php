<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

final class OtlpEndpoint
{
    private const DEFAULT_URL = 'http://localhost:4318/v1/traces';

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            self::normalizeUrl(self::configuredEndpoint($config)),
            self::headers($config),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function configuredEndpoint(array $config): string
    {
        $endpoint = $config['endpoint'] ?? null;

        if (is_string($endpoint) && trim($endpoint) !== '') {
            return trim($endpoint);
        }

        foreach (['OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', 'OTEL_EXPORTER_OTLP_ENDPOINT'] as $name) {
            $value = getenv($name);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return self::DEFAULT_URL;
    }

    private static function normalizeUrl(string $url): string
    {
        $url = rtrim($url, '/');

        if (str_ends_with($url, '/v1/traces')) {
            return $url;
        }

        return "{$url}/v1/traces";
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private static function headers(array $config): array
    {
        return array_merge(
            self::environmentHeaders('OTEL_EXPORTER_OTLP_HEADERS'),
            self::environmentHeaders('OTEL_EXPORTER_OTLP_TRACES_HEADERS'),
            self::configuredHeaders($config['headers'] ?? []),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function environmentHeaders(string $name): array
    {
        $value = getenv($name);

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $headers = [];

        foreach (explode(',', $value) as $part) {
            [$key, $headerValue] = array_pad(explode('=', $part, 2), 2, null);

            $key = is_string($key) ? trim($key) : '';
            $headerValue = is_string($headerValue) ? trim($headerValue) : '';

            if ($key === '' || $headerValue === '') {
                continue;
            }

            $headers[$key] = $headerValue;
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private static function configuredHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $configured = [];

        foreach ($headers as $key => $value) {
            if (! is_string($key) || $key === '' || $value === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $configured[$key] = $value;
        }

        return $configured;
    }
}
