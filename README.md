# Laravel AI Observability

OpenInference observability for the Laravel AI SDK.

This package listens to `laravel/ai` events, maps agent and model activity into OpenInference-style traces and spans, and exports them to local logs, an OTLP collector, supported observability tools, or your database.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai` `^0.7`

## Installation

```bash
composer require tracefast/laravel-ai-observability
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=ai-observability-config
```

## Configuration

Enable tracing and choose an exporter in your `.env` file:

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=stack
AI_OBSERVABILITY_EXPORT_MODE=defer
AI_OBSERVABILITY_EXPORT_TIMEOUT=2.0
```

The default exporter is `stack`, and the default stack sends traces to the `log` exporter. `AI_OBSERVABILITY_EXPORT_MODE` accepts `defer` or `sync`; the default `defer` mode uses Laravel deferred functions so export work runs after the response when Laravel can defer it.

## Content Capture Warning

V1 captures full LLM input and output by default. Captured content may include prompts, system messages, tool arguments, tool results, uploaded content, PII, secrets, and sensitive business data.

Make the default explicit when you want full capture:

```env
AI_OBSERVABILITY_CAPTURE_CONTENT=full
```

Disable content capture when needed:

```env
AI_OBSERVABILITY_CAPTURE_CONTENT=off
```

## Exporters

Set `AI_OBSERVABILITY_EXPORTER` to one of the configured exporters in `config/ai-observability.php`.

### Log

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=log
AI_OBSERVABILITY_LOG_CHANNEL=stack
AI_OBSERVABILITY_LOG_LEVEL=debug
```

### Phoenix

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=phoenix
PHOENIX_COLLECTOR_ENDPOINT=http://localhost:6006/v1/traces
```

### Langfuse

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=langfuse
LANGFUSE_OTEL_ENDPOINT=https://cloud.langfuse.com/api/public/otel/v1/traces
LANGFUSE_OTEL_AUTHORIZATION="Basic <base64-public-key-colon-secret-key>"
LANGFUSE_INGESTION_VERSION=4
```

### Braintrust

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=braintrust
BRAINTRUST_OTEL_ENDPOINT=https://api.braintrust.dev/otel/v1/traces
BRAINTRUST_API_KEY=<braintrust-api-key>
BRAINTRUST_PARENT=<project-or-parent-resource>
```

### Stack

The `stack` exporter fans out to multiple configured exporters:

```php
'default' => env('AI_OBSERVABILITY_EXPORTER', 'stack'),

'exporters' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['log', 'phoenix'],
    ],
],
```

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=stack
```

### Database

Publish the migrations, run them, and use the `database` exporter:

```bash
php artisan vendor:publish --tag=ai-observability-migrations
php artisan migrate
```

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=database
AI_OBSERVABILITY_DB_CONNECTION=mysql
```

Leave `AI_OBSERVABILITY_DB_CONNECTION` empty to use Laravel's default database connection.

The database exporter stores traces and spans only. The published migration creates `ai_observability_traces` and `ai_observability_spans`.

## Custom Exporters

Custom exporters implement `Tracefast\LaravelAiObservability\Contracts\Exporter`:

```php
<?php

namespace App\Observability;

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

Register a custom driver from a service provider:

```php
<?php

namespace App\Providers;

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

Then add the exporter to `config/ai-observability.php`:

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
