# Laravel AI Observability

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tracefast/laravel-ai-observability.svg?style=flat-square)](https://packagist.org/packages/tracefast/laravel-ai-observability)
[![Total Downloads](https://img.shields.io/packagist/dt/tracefast/laravel-ai-observability.svg?style=flat-square)](https://packagist.org/packages/tracefast/laravel-ai-observability)
[![PHP Version](https://img.shields.io/packagist/php-v/tracefast/laravel-ai-observability.svg?style=flat-square)](https://packagist.org/packages/tracefast/laravel-ai-observability)
[![License](https://img.shields.io/packagist/l/tracefast/laravel-ai-observability.svg?style=flat-square)](LICENSE.md)

OpenInference traces for the Laravel AI SDK.

This package listens to `laravel/ai` events and exports agent runs, model calls, tool calls, inputs, outputs, usage, and errors to logs, OTLP receivers, or a local database.

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
AI_OBSERVABILITY_EXPORT_TIMEOUT=2.0
```

`AI_OBSERVABILITY_EXPORT_MODE` accepts `defer` or `sync`. The default is `defer`, which exports after the response when Laravel can defer work.

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
