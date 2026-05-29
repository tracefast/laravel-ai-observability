# Simplified Configuration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Laravel AI Observability usable with minimal env configuration: enabled by default, logging by default, and comma-separated exporters for multi-receiver export.

**Architecture:** Keep the public contract vendor-neutral. `config/ai-observability.php` provides safer defaults, and `ExporterManager` interprets comma-separated exporter names as an implicit stack without changing existing explicit stack configuration. README examples become short happy paths, with advanced knobs moved below the quick start.

**Tech Stack:** PHP 8.4, Laravel 12/13 package config, spatie/laravel-package-tools, Pest 4, Laravel Pint.

---

## File Structure

- Modify `config/ai-observability.php`
  - Defaults `AI_OBSERVABILITY_ENABLED` to `true`.
  - Defaults `AI_OBSERVABILITY_EXPORTER` to `log`.
- Modify `src/Exporters/ExporterManager.php`
  - Parse comma-separated exporter names.
  - Return a `StackExporter` when a list contains more than one exporter.
  - Keep existing explicit stack behavior unchanged.
- Modify `tests/Feature/PackageBootTest.php`
  - Update default config expectations.
- Modify `tests/Feature/ExporterManagerTest.php`
  - Add tests for comma-separated exporter lists and trimming.
- Create `tests/Feature/DocumentationTest.php`
  - Assert the README documents simplified defaults and multi-receiver syntax.
- Modify `README.md`
  - Rewrite for a concise package README.
  - Show `AI_OBSERVABILITY_EXPORTER=phoenix,langfuse` as the multi-receiver path.

## Task 1: Update Default Configuration

**Files:**
- Modify: `tests/Feature/PackageBootTest.php`
- Modify: `config/ai-observability.php`

- [ ] **Step 1: Write the failing default config test**

Replace the first test in `tests/Feature/PackageBootTest.php` with:

```php
it('registers the package config and root service', function (): void {
    expect(config('ai-observability.enabled'))->toBeTrue()
        ->and(config('ai-observability.default'))->toBe('log')
        ->and(config('ai-observability.capture.content'))->toBe('full')
        ->and(config('ai-observability.export.mode'))->toBe('defer')
        ->and(app(AiObservability::class))->toBeInstanceOf(AiObservability::class)
        ->and(app(ObservationContext::class))->toBeInstanceOf(ObservationContext::class);
});
```

- [ ] **Step 2: Run the focused failing test**

Run:

```bash
php84 vendor/bin/pest tests/Feature/PackageBootTest.php --filter='registers the package config'
```

Expected: FAIL because config still defaults `enabled` to `false` and default exporter to `stack`.

- [ ] **Step 3: Update package config defaults**

In `config/ai-observability.php`, replace:

```php
'enabled' => env('AI_OBSERVABILITY_ENABLED', false),

'default' => env('AI_OBSERVABILITY_EXPORTER', 'stack'),
```

with:

```php
'enabled' => env('AI_OBSERVABILITY_ENABLED', true),

'default' => env('AI_OBSERVABILITY_EXPORTER', 'log'),
```

- [ ] **Step 4: Verify the focused test passes**

Run:

```bash
php84 vendor/bin/pest tests/Feature/PackageBootTest.php --filter='registers the package config'
```

Expected: PASS.

- [ ] **Step 5: Commit Task 1**

Run:

```bash
git add config/ai-observability.php tests/Feature/PackageBootTest.php
git commit -m "fix: enable log exporter by default"
```

## Task 2: Support Comma-Separated Exporters

**Files:**
- Modify: `tests/Feature/ExporterManagerTest.php`
- Modify: `src/Exporters/ExporterManager.php`

- [ ] **Step 1: Add failing tests for implicit stacks**

Append these tests to `tests/Feature/ExporterManagerTest.php` after `it('resolves stack exporters from configured channels', ...)`:

```php
it('resolves comma separated exporters as an implicit stack', function (): void {
    expect(app(ExporterManager::class)->exporter('null,log'))->toBeInstanceOf(StackExporter::class);
});

it('trims comma separated exporter names', function (): void {
    expect(app(ExporterManager::class)->exporter(' null , log '))->toBeInstanceOf(StackExporter::class);
});
```

- [ ] **Step 2: Run the focused failing tests**

Run:

```bash
php84 vendor/bin/pest tests/Feature/ExporterManagerTest.php --filter='comma separated|trims comma'
```

Expected: FAIL with `Exporter [null,log] is not configured.`

- [ ] **Step 3: Implement exporter list parsing**

In `src/Exporters/ExporterManager.php`, replace the `exporter()` method with:

```php
public function exporter(?string $name = null): Exporter
{
    $name ??= (string) config('ai-observability.default', 'null');
    $name = trim($name);

    $channels = $this->exporterNames($name);

    if (count($channels) > 1) {
        return new StackExporter(array_map(
            fn (string $channel): Exporter => $this->exporter($channel),
            $channels,
        ));
    }

    $name = $channels[0] ?? $name;

    if (isset($this->exporters[$name])) {
        return $this->exporters[$name];
    }

    if (isset($this->resolving[$name])) {
        throw new InvalidArgumentException("Circular exporter stack detected while resolving [{$name}].");
    }

    $this->resolving[$name] = true;

    try {
        return $this->exporters[$name] = $this->resolve($name);
    } finally {
        unset($this->resolving[$name]);
    }
}
```

Add this private method above `resolve()`:

```php
/**
 * @return list<string>
 */
private function exporterNames(string $name): array
{
    if (! str_contains($name, ',')) {
        return [$name];
    }

    return array_values(array_filter(
        array_map(
            fn (string $channel): string => trim($channel),
            explode(',', $name),
        ),
        fn (string $channel): bool => $channel !== '',
    ));
}
```

- [ ] **Step 4: Verify comma-separated exporter tests pass**

Run:

```bash
php84 vendor/bin/pest tests/Feature/ExporterManagerTest.php --filter='comma separated|trims comma'
```

Expected: PASS.

- [ ] **Step 5: Run full exporter manager tests**

Run:

```bash
php84 vendor/bin/pest tests/Feature/ExporterManagerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit Task 2**

Run:

```bash
git add src/Exporters/ExporterManager.php tests/Feature/ExporterManagerTest.php
git commit -m "feat: support comma separated exporters"
```

## Task 3: Rewrite README

**Files:**
- Create: `tests/Feature/DocumentationTest.php`
- Modify: `README.md`

- [ ] **Step 1: Write the failing README contract test**

Create `tests/Feature/DocumentationTest.php`:

```php
<?php

declare(strict_types=1);

it('documents the simplified exporter configuration', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)->toContain('Observability is enabled by default')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORTER=log')
        ->and($readme)->toContain('AI_OBSERVABILITY_EXPORTER=phoenix,langfuse')
        ->and($readme)->toContain('BRAINTRUST_API_KEY=<braintrust-api-key>')
        ->and($readme)->toContain('AI_OBSERVABILITY_CAPTURE_CONTENT=off')
        ->and($readme)->toContain('AiObservability::withSession(');
});
```

- [ ] **Step 2: Run the failing README test**

Run:

```bash
php84 vendor/bin/pest tests/Feature/DocumentationTest.php
```

Expected: FAIL because the current README does not yet say observability is enabled by default and does not show `AI_OBSERVABILITY_EXPORTER=phoenix,langfuse`.

- [ ] **Step 3: Replace README content**

Replace `README.md` with:

````markdown
# Laravel AI Observability

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
````

- [ ] **Step 4: Verify the README test passes**

Run:

```bash
php84 vendor/bin/pest tests/Feature/DocumentationTest.php
```

Expected: PASS.

- [ ] **Step 5: Check README examples manually**

Run:

```bash
rg -n "AI_OBSERVABILITY_ENABLED|AI_OBSERVABILITY_EXPORTER=phoenix,langfuse|BRAINTRUST_API_KEY|AI_OBSERVABILITY_CAPTURE_CONTENT=off" README.md
```

Expected: output includes the advanced `AI_OBSERVABILITY_ENABLED=true`, multi-receiver example, Braintrust API key example, and content capture opt-out.

- [ ] **Step 6: Commit Task 3**

Run:

```bash
git add README.md tests/Feature/DocumentationTest.php
git commit -m "docs: simplify configuration readme"
```

## Task 4: Final Verification

**Files:**
- Verify all modified package files.

- [ ] **Step 1: Run package formatter**

Run:

```bash
php84 vendor/bin/pint --dirty --format=agent
```

Expected: `{"tool":"pint","result":"passed"}`.

- [ ] **Step 2: Run full package tests**

Run:

```bash
php84 vendor/bin/pest
```

Expected: PASS for all package tests.

- [ ] **Step 3: Run whitespace check**

Run:

```bash
git diff --check
```

Expected: no output.

- [ ] **Step 4: Inspect final diff**

Run:

```bash
git diff --stat HEAD~3..HEAD
```

Expected: changes include `config/ai-observability.php`, `src/Exporters/ExporterManager.php`, README, and related tests.

- [ ] **Step 5: Push package changes**

Run:

```bash
git push origin main
```

Expected: push succeeds to `https://github.com/tracefast/laravel-ai-observability.git`.
