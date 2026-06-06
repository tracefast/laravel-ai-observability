# Laravel AI Observability

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tracefast/laravel-ai-observability.svg?style=flat-square)](https://packagist.org/packages/tracefast/laravel-ai-observability)
[![Total Downloads](https://img.shields.io/packagist/dt/tracefast/laravel-ai-observability.svg?style=flat-square)](https://packagist.org/packages/tracefast/laravel-ai-observability)
[![PHP Version](https://img.shields.io/packagist/php-v/tracefast/laravel-ai-observability.svg?style=flat-square)](https://packagist.org/packages/tracefast/laravel-ai-observability)
[![License](https://img.shields.io/packagist/l/tracefast/laravel-ai-observability.svg?style=flat-square)](LICENSE.md)

OpenInference traces for the Laravel AI SDK.

This package listens to `laravel/ai` events and exports agent runs, model calls, tool calls, inputs, outputs, usage, and errors to logs, OTLP receivers, or a local database.

It is designed to be safe to install early in a project: the default setup writes structured traces to your existing Laravel log, avoids network calls, and runs export work through Laravel's deferred callback lifecycle where available. When you are ready for a collector, switch the exporter to Phoenix, Langfuse, Braintrust, generic OTLP, database storage, or your own driver.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai` `^0.7`

## Installation

```bash
composer require tracefast/laravel-ai-observability
```

That is enough to start capturing traces. Observability is enabled by default and writes to your Laravel log.

Publish the config only when you want to customize exporters:

```bash
php artisan vendor:publish --tag=ai-observability-config
```

## Quick Start

The default exporter is `log`:

```env
# Optional. This is the default.
AI_OBSERVABILITY_EXPORTER=log
```

V1 captures full LLM input and output by default.

For remote collectors in production, keep the default `defer` export mode or move exports to `queue` or `background` so trace delivery does not sit directly in the request path.

## Exporters

Set `AI_OBSERVABILITY_EXPORTER` to one exporter name:

```env
AI_OBSERVABILITY_EXPORTER=phoenix
```

Or send traces to multiple receivers:

```env
AI_OBSERVABILITY_EXPORTER=phoenix,langfuse
```

### Phoenix

```env
AI_OBSERVABILITY_EXPORTER=phoenix
PHOENIX_COLLECTOR_ENDPOINT=http://localhost:6006/v1/traces
```

### Langfuse

```env
AI_OBSERVABILITY_EXPORTER=langfuse
LANGFUSE_OTEL_ENDPOINT=https://cloud.langfuse.com/api/public/otel/v1/traces
LANGFUSE_OTEL_AUTHORIZATION="Basic <base64-public-key-colon-secret-key>"
```

### Braintrust

```env
AI_OBSERVABILITY_EXPORTER=braintrust
BRAINTRUST_API_KEY=<braintrust-api-key>
BRAINTRUST_PARENT=project_name:<project-name>
```

The Braintrust endpoint defaults to `https://api.braintrust.dev/otel/v1/traces`.

### Log

```env
AI_OBSERVABILITY_EXPORTER=log
```

Advanced log options:

```env
AI_OBSERVABILITY_LOG_CHANNEL=stack
AI_OBSERVABILITY_LOG_LEVEL=debug
```

## Content Capture

By default, this package captures full input and output. That may include prompts, system messages, tool arguments, tool results, uploaded content, PII, secrets, and sensitive business data.

Disable content capture when needed:

```env
AI_OBSERVABILITY_CAPTURE_CONTENT=off
```

## Conversation Correlation

Use `AiObservability::withSession()` when your app has its own conversation id:

```php
use Tracefast\LaravelAiObservability\Facades\AiObservability;

$response = AiObservability::withSession(
    sessionId: $conversation->uuid,
    callback: fn () => $agent->prompt($message),
    userId: $user->id,
);
```

Each turn remains its own trace, and every turn carries the same `session.id`.

## Database Exporter

The database exporter is opt-in.

```bash
php artisan vendor:publish --tag=ai-observability-migrations
php artisan migrate
```

```env
AI_OBSERVABILITY_EXPORTER=database
```

Use a specific connection when needed:

```env
AI_OBSERVABILITY_DB_CONNECTION=mysql
```

The migration creates `ai_observability_traces` and `ai_observability_spans`.

## Advanced Options

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORT_MODE=defer
AI_OBSERVABILITY_SAMPLE_RATE=1.0
AI_OBSERVABILITY_EXPORT_TIMEOUT=2.0
AI_OBSERVABILITY_EXPORT_CONNECT_TIMEOUT=0.5
AI_OBSERVABILITY_MAX_PAYLOAD_BYTES=1048576
```

`AI_OBSERVABILITY_EXPORT_MODE` accepts `defer`, `sync`, `queue`, or `background`. The default is `defer`, which exports after the response when Laravel can defer work. Deferred exports also run at Laravel command and queue job boundaries through Laravel's deferred callback lifecycle.

Use `queue` when you want exports handled by a normal queue worker:

```env
AI_OBSERVABILITY_EXPORT_MODE=queue
AI_OBSERVABILITY_EXPORT_CONNECTION=redis
AI_OBSERVABILITY_EXPORT_QUEUE=observability
```

Use `background` when your Laravel app has a `background` queue connection and you want to release the current PHP worker quickly:

```env
AI_OBSERVABILITY_EXPORT_MODE=background
```

### Production Transport Hardening

```env
AI_OBSERVABILITY_EXPORT_TIMEOUT=2.0
AI_OBSERVABILITY_EXPORT_CONNECT_TIMEOUT=0.5
AI_OBSERVABILITY_MAX_PAYLOAD_BYTES=1048576
AI_OBSERVABILITY_EXPORT_RETRY_ATTEMPTS=1
AI_OBSERVABILITY_EXPORT_RETRY_DELAY_MS=100
AI_OBSERVABILITY_OTLP_COMPRESSION=gzip
AI_OBSERVABILITY_CIRCUIT_BREAKER=true
AI_OBSERVABILITY_CIRCUIT_BREAKER_FAILURE_THRESHOLD=3
AI_OBSERVABILITY_CIRCUIT_BREAKER_OPEN_SECONDS=30
```

Payloads over `AI_OBSERVABILITY_MAX_PAYLOAD_BYTES` are dropped and reported locally instead of being sent. OTLP retries are bounded and only intended for transient collector failures such as network errors, `408`, `425`, `429`, and `5xx` responses. The circuit breaker skips sends for a short window after repeated failures so a dead collector does not keep taxing request workers.

### Generic OTLP

For Laravel apps that run `php artisan optimize` or otherwise cache config, prefer the package-specific variables so endpoint and headers are baked into Laravel's cached config:

```env
AI_OBSERVABILITY_EXPORTER=otlp
AI_OBSERVABILITY_OTLP_ENDPOINT=https://collector.example.com/v1/traces
AI_OBSERVABILITY_OTLP_HEADERS="Authorization=Bearer <token>"
```

When sending to Tracefast, you can set the project key directly:

```env
AI_OBSERVABILITY_EXPORTER=otlp
AI_OBSERVABILITY_OTLP_ENDPOINT=https://collector.tracefast.dev/v1/traces
TRACEFAST_API_KEY=<tracefast-project-api-key>
```

The package also honors standard OTEL variables when they are available as real process environment variables. As of v1.1.1, those variables are also bridged through Laravel config when they are present in `.env` during config caching:

```env
AI_OBSERVABILITY_EXPORTER=otlp
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://collector.example.com/v1/traces
OTEL_EXPORTER_OTLP_TRACES_HEADERS="Authorization=Bearer <token>"
```

OTLP payloads include OpenInference and versioned Tracefast metadata such as `openinference.schema.version`, `tracefast.ai.sdk.version`, `tracefast.ai.package.version`, and `gen_ai.*` attributes where the Laravel AI SDK exposes the source data.

## Custom Exporters

Custom exporters implement `Tracefast\LaravelAiObservability\Contracts\Exporter`:

```php
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

final class WebhookExporter implements Exporter
{
    public function export(Trace $trace): void
    {
        // Send $trace->toArray() to your destination.
    }
}
```

Register the driver from a service provider:

```php
use App\Observability\WebhookExporter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Tracefast\LaravelAiObservability\AiObservability;
use Tracefast\LaravelAiObservability\Contracts\Exporter;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(AiObservability $observability): void
    {
        $observability->extend(
            'webhook',
            fn (Application $app, array $config, string $name): Exporter => new WebhookExporter(),
        );
    }
}
```

Then configure it:

```php
'exporters' => [
    'webhook' => [
        'driver' => 'webhook',
    ],
],
```

## Testing

```bash
composer test
```

## License

Laravel AI Observability is open-sourced software licensed under the MIT license.
