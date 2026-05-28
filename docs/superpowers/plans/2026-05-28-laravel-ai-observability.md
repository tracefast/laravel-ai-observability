# Laravel AI Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `tracefast/laravel-ai-observability`, a standalone Laravel package that observes `laravel/ai` agent runs and exports OpenInference-style traces/spans to log, OTLP, database, null, or stacked exporters.

**Architecture:** The package listens to Laravel AI SDK lifecycle events through a compatibility adapter, stores an in-memory trace/span model keyed by invocation ID, and exports completed traces through a Laravel logging-style exporter manager. OpenInference attributes are the primary semantic model; OTLP is a transport used by Phoenix, Langfuse, Braintrust, and generic receivers.

**Tech Stack:** PHP 8.4+, Laravel 12/13, `laravel/ai:^0.7`, `spatie/laravel-package-tools`, Pest 4, Orchestra Testbench, Laravel HTTP client for OTLP/HTTP JSON.

---

## Target File Structure

Create and maintain these files:

- `composer.json`: package metadata, dependencies, autoloading, scripts, Laravel auto-discovery.
- `LICENSE.md`: MIT license.
- `README.md`: install, config, exporter examples, content capture warning.
- `config/ai-observability.php`: public package configuration.
- `phpunit.xml.dist`: package-local test suite configuration.
- `docs/superpowers/specs/2026-05-28-laravel-ai-observability-design.md`: approved design reference.
- `docs/superpowers/plans/2026-05-28-laravel-ai-observability.md`: this implementation plan.
- `database/migrations/create_ai_observability_tables.php.stub`: optional database exporter migration.
- `src/LaravelAiObservabilityServiceProvider.php`: Spatie package service provider.
- `src/AiObservability.php`: package facade root and exporter manager wrapper.
- `src/Facades/AiObservability.php`: Laravel facade.
- `src/Contracts/Exporter.php`: exporter contract.
- `src/Contracts/TraceRegistry.php`: trace registry contract.
- `src/Data/Trace.php`: OpenInference trace value object.
- `src/Data/Span.php`: OpenInference span value object.
- `src/Data/SpanKind.php`: span kind enum.
- `src/Data/SpanStatus.php`: span status enum.
- `src/Exporters/ExporterManager.php`: resolves configured exporters.
- `src/Exporters/NullExporter.php`: drops traces.
- `src/Exporters/LogExporter.php`: writes structured traces to logs.
- `src/Exporters/StackExporter.php`: fan-out exporter.
- `src/Exporters/DatabaseExporter.php`: stores traces and spans.
- `src/Exporters/OtlpExporter.php`: exports OTLP/HTTP JSON traces.
- `src/Exporters/OtlpEndpoint.php`: resolves endpoint, headers, and preset defaults.
- `src/Registry/InMemoryTraceRegistry.php`: invocation-keyed trace registry.
- `src/Support/Clock.php`: timestamp helper for testable duration calculations.
- `src/Support/Ids.php`: trace/span ID generation.
- `src/Support/Arr.php`: tiny array helpers for attribute cleanup.
- `src/LaravelAi/LaravelAiEventSubscriber.php`: subscribes to Laravel AI events.
- `src/LaravelAi/LaravelAiEventMapper.php`: extracts prompt/response/tool fields from event objects defensively.
- `src/LaravelAi/EventClassMap.php`: central class-name map for Laravel AI SDK events.
- `tests/Pest.php`: Pest bootstrap.
- `tests/TestCase.php`: Orchestra Testbench base test case.
- `tests/Fixtures/FakeEvents.php`: simple event fixtures for compatibility tests.
- `tests/Feature/PackageBootTest.php`: service provider and config tests.
- `tests/Feature/OpenInferenceModelTest.php`: trace/span model tests.
- `tests/Feature/ExporterManagerTest.php`: exporter manager and stack tests.
- `tests/Feature/LogAndNullExporterTest.php`: log/null exporter tests.
- `tests/Feature/DatabaseExporterTest.php`: database exporter tests.
- `tests/Feature/OtlpExporterTest.php`: OTLP payload and preset tests.
- `tests/Feature/LaravelAiEventSubscriberTest.php`: event-to-trace integration tests.

## Task 1: Package Scaffold And Boot Test

**Files:**
- Create: `composer.json`
- Create: `LICENSE.md`
- Create: `config/ai-observability.php`
- Create: `phpunit.xml.dist`
- Create: `src/LaravelAiObservabilityServiceProvider.php`
- Create: `src/AiObservability.php`
- Create: `src/Facades/AiObservability.php`
- Create: `tests/Pest.php`
- Create: `tests/TestCase.php`
- Create: `tests/Feature/PackageBootTest.php`

- [ ] **Step 1: Create package manifest**

Create `composer.json`:

```json
{
    "name": "tracefast/laravel-ai-observability",
    "description": "OpenInference observability for Laravel AI SDK agents.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "illuminate/contracts": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "illuminate/http": "^12.0|^13.0",
        "illuminate/log": "^12.0|^13.0",
        "illuminate/support": "^12.0|^13.0",
        "laravel/ai": "^0.7",
        "spatie/laravel-package-tools": "^1.16"
    },
    "require-dev": {
        "laravel/pint": "^1.29",
        "orchestra/testbench": "^10.0|^11.0",
        "pestphp/pest": "^4.0",
        "pestphp/pest-plugin-laravel": "^4.0"
    },
    "autoload": {
        "psr-4": {
            "Tracefast\\LaravelAiObservability\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tracefast\\LaravelAiObservability\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Tracefast\\LaravelAiObservability\\LaravelAiObservabilityServiceProvider"
            ],
            "aliases": {
                "AiObservability": "Tracefast\\LaravelAiObservability\\Facades\\AiObservability"
            }
        }
    },
    "scripts": {
        "test": "pest",
        "format": "pint"
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "stable",
    "prefer-stable": true,
    "keywords": [
        "laravel",
        "laravel-ai",
        "openinference",
        "opentelemetry",
        "otlp",
        "phoenix",
        "langfuse",
        "braintrust",
        "observability"
    ]
}
```

- [ ] **Step 2: Create MIT license**

Create `LICENSE.md`:

```text
MIT License

Copyright (c) 2026 TraceFast

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 3: Create default config**

Create `config/ai-observability.php`:

```php
<?php

return [
    'enabled' => env('AI_OBSERVABILITY_ENABLED', false),

    'default' => env('AI_OBSERVABILITY_EXPORTER', 'stack'),

    'capture' => [
        'content' => env('AI_OBSERVABILITY_CAPTURE_CONTENT', 'full'),
    ],

    'export' => [
        'mode' => env('AI_OBSERVABILITY_EXPORT_MODE', 'defer'),
        'timeout' => (float) env('AI_OBSERVABILITY_EXPORT_TIMEOUT', 2.0),
    ],

    'exporters' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['log'],
        ],

        'log' => [
            'driver' => 'log',
            'channel' => env('AI_OBSERVABILITY_LOG_CHANNEL'),
            'level' => env('AI_OBSERVABILITY_LOG_LEVEL', 'debug'),
        ],

        'phoenix' => [
            'driver' => 'otlp',
            'preset' => 'phoenix',
            'endpoint' => env('PHOENIX_COLLECTOR_ENDPOINT', 'http://localhost:6006/v1/traces'),
            'headers' => [],
        ],

        'langfuse' => [
            'driver' => 'otlp',
            'preset' => 'langfuse',
            'endpoint' => env('LANGFUSE_OTEL_ENDPOINT'),
            'headers' => [
                'Authorization' => env('LANGFUSE_OTEL_AUTHORIZATION'),
                'x-langfuse-ingestion-version' => env('LANGFUSE_INGESTION_VERSION', '4'),
            ],
        ],

        'braintrust' => [
            'driver' => 'otlp',
            'preset' => 'braintrust',
            'endpoint' => env('BRAINTRUST_OTEL_ENDPOINT', 'https://api.braintrust.dev/otel/v1/traces'),
            'headers' => [
                'Authorization' => env('BRAINTRUST_API_KEY') ? 'Bearer '.env('BRAINTRUST_API_KEY') : null,
                'x-bt-parent' => env('BRAINTRUST_PARENT'),
            ],
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('AI_OBSERVABILITY_DB_CONNECTION'),
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
```

- [ ] **Step 4: Create test harness**

Create `phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Laravel AI Observability">
            <directory suffix="Test.php">tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_KEY" value="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="/>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="testing"/>
    </php>
</phpunit>
```

Create `tests/Pest.php`:

```php
<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\Tests\TestCase;

pest()->extends(TestCase::class)->in('Feature');
```

Create `tests/TestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tracefast\LaravelAiObservability\LaravelAiObservabilityServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiObservabilityServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
```

- [ ] **Step 5: Write failing package boot test**

Create `tests/Feature/PackageBootTest.php`:

```php
<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\AiObservability;

it('registers the package config and root service', function (): void {
    expect(config('ai-observability.default'))->toBe('stack')
        ->and(config('ai-observability.capture.content'))->toBe('full')
        ->and(app(AiObservability::class))->toBeInstanceOf(AiObservability::class);
});
```

- [ ] **Step 6: Run test to verify it fails**

Run:

```bash
composer install
vendor/bin/pest tests/Feature/PackageBootTest.php --stop-on-failure
```

Expected: FAIL because `Tracefast\LaravelAiObservability\AiObservability` and the service provider do not exist.

- [ ] **Step 7: Implement service provider and facade root**

Create `src/LaravelAiObservabilityServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelAiObservabilityServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-observability')
            ->hasConfigFile()
            ->hasMigration('create_ai_observability_tables');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(AiObservability::class, fn (): AiObservability => new AiObservability);
        $this->app->alias(AiObservability::class, 'ai-observability');
    }
}
```

Create `src/AiObservability.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

class AiObservability
{
    public function enabled(): bool
    {
        return (bool) config('ai-observability.enabled', false);
    }
}
```

Create `src/Facades/AiObservability.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 */
class AiObservability extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai-observability';
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run:

```bash
vendor/bin/pest tests/Feature/PackageBootTest.php
```

Expected: PASS.

- [ ] **Step 9: Commit scaffold**

Run:

```bash
git add composer.json LICENSE.md config/ai-observability.php phpunit.xml.dist src tests
git commit -m "feat: scaffold laravel ai observability package"
```

## Task 2: OpenInference Trace And Span Model

**Files:**
- Create: `src/Data/SpanKind.php`
- Create: `src/Data/SpanStatus.php`
- Create: `src/Data/Span.php`
- Create: `src/Data/Trace.php`
- Create: `src/Support/Ids.php`
- Create: `src/Support/Clock.php`
- Create: `src/Support/Arr.php`
- Create: `tests/Feature/OpenInferenceModelTest.php`

- [ ] **Step 1: Write failing model tests**

Create `tests/Feature/OpenInferenceModelTest.php`:

```php
<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;

it('serializes a trace with OpenInference span attributes', function (): void {
    $trace = new Trace(
        traceId: '1234567890abcdef1234567890abcdef',
        name: 'agent run',
        startedAt: '2026-05-28T10:00:00.000000Z',
    );

    $span = new Span(
        traceId: $trace->traceId,
        spanId: '1234567890abcdef',
        parentSpanId: null,
        name: 'SalesCoach',
        kind: SpanKind::Agent,
        status: SpanStatus::Ok,
        startedAt: '2026-05-28T10:00:00.000000Z',
        endedAt: '2026-05-28T10:00:01.000000Z',
        attributes: [
            'openinference.span.kind' => 'agent',
            'tracefast.ai.invocation_id' => 'inv_123',
        ],
        input: ['value' => 'Hello'],
        output: ['value' => 'Hi'],
    );

    $trace->addSpan($span);
    $trace->finish('2026-05-28T10:00:01.000000Z', SpanStatus::Ok);

    expect($trace->toArray())->toMatchArray([
        'trace_id' => '1234567890abcdef1234567890abcdef',
        'name' => 'agent run',
        'status' => 'ok',
        'duration_ms' => 1000.0,
        'spans' => [
            [
                'span_id' => '1234567890abcdef',
                'kind' => 'agent',
                'attributes' => [
                    'openinference.span.kind' => 'agent',
                    'tracefast.ai.invocation_id' => 'inv_123',
                ],
            ],
        ],
    ]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/pest tests/Feature/OpenInferenceModelTest.php --stop-on-failure
```

Expected: FAIL because the data classes do not exist.

- [ ] **Step 3: Implement support classes**

Create `src/Data/SpanKind.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

enum SpanKind: string
{
    case Agent = 'agent';
    case Chain = 'chain';
    case Llm = 'llm';
    case Tool = 'tool';
    case Embedding = 'embedding';
    case Retriever = 'retriever';
    case Reranker = 'reranker';
}
```

Create `src/Data/SpanStatus.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
```

Create `src/Support/Clock.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class Clock
{
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function durationMs(string $startedAt, ?string $endedAt): ?float
    {
        if ($endedAt === null) {
            return null;
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $startedAt, new DateTimeZone('UTC'));
        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u\Z', $endedAt, new DateTimeZone('UTC'));

        if (! $start instanceof DateTimeInterface || ! $end instanceof DateTimeInterface) {
            return null;
        }

        return round(((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000, 3);
    }
}
```

Create `src/Support/Ids.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

use Illuminate\Support\Str;

class Ids
{
    public static function traceId(): string
    {
        return Str::lower(Str::random(32));
    }

    public static function spanId(): string
    {
        return Str::lower(Str::random(16));
    }
}
```

Create `src/Support/Arr.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

class Arr
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null);
    }
}
```

- [ ] **Step 4: Implement trace and span value objects**

Create `src/Data/Span.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

use Tracefast\LaravelAiObservability\Support\Clock;

class Span
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $input
     * @param  array<string, mixed>|null  $output
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentSpanId,
        public readonly string $name,
        public readonly SpanKind $kind,
        public SpanStatus $status,
        public readonly string $startedAt,
        public ?string $endedAt = null,
        public array $attributes = [],
        public ?array $input = null,
        public ?array $output = null,
        public ?string $errorType = null,
        public ?string $errorMessage = null,
    ) {}

    public function finish(string $endedAt, SpanStatus $status = SpanStatus::Ok): void
    {
        $this->endedAt = $endedAt;
        $this->status = $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_ms' => Clock::durationMs($this->startedAt, $this->endedAt),
            'attributes' => $this->attributes,
            'input' => $this->input,
            'output' => $this->output,
            'error_type' => $this->errorType,
            'error_message' => $this->errorMessage,
        ];
    }
}
```

Create `src/Data/Trace.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

use Tracefast\LaravelAiObservability\Support\Clock;

class Trace
{
    /**
     * @var array<int, Span>
     */
    private array $spans = [];

    public SpanStatus $status = SpanStatus::Unset;

    public ?string $endedAt = null;

    public function __construct(
        public readonly string $traceId,
        public readonly string $name,
        public readonly string $startedAt,
    ) {}

    public function addSpan(Span $span): void
    {
        $this->spans[] = $span;
    }

    /**
     * @return array<int, Span>
     */
    public function spans(): array
    {
        return $this->spans;
    }

    public function finish(string $endedAt, SpanStatus $status = SpanStatus::Ok): void
    {
        $this->endedAt = $endedAt;
        $this->status = $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'name' => $this->name,
            'status' => $this->status->value,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_ms' => Clock::durationMs($this->startedAt, $this->endedAt),
            'spans' => array_map(fn (Span $span): array => $span->toArray(), $this->spans),
        ];
    }
}
```

- [ ] **Step 5: Run model tests**

Run:

```bash
vendor/bin/pest tests/Feature/OpenInferenceModelTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit model**

Run:

```bash
git add src/Data src/Support tests/Feature/OpenInferenceModelTest.php
git commit -m "feat: add openinference trace model"
```

## Task 3: Exporter Contracts, Manager, Null, Log, And Stack

**Files:**
- Create: `src/Contracts/Exporter.php`
- Create: `src/Exporters/ExporterManager.php`
- Create: `src/Exporters/NullExporter.php`
- Create: `src/Exporters/LogExporter.php`
- Create: `src/Exporters/StackExporter.php`
- Modify: `src/AiObservability.php`
- Modify: `src/LaravelAiObservabilityServiceProvider.php`
- Create: `tests/Feature/ExporterManagerTest.php`
- Create: `tests/Feature/LogAndNullExporterTest.php`

- [ ] **Step 1: Write failing exporter tests**

Create `tests/Feature/ExporterManagerTest.php`:

```php
<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;
use Tracefast\LaravelAiObservability\Exporters\NullExporter;

it('resolves the default exporter from config', function (): void {
    config()->set('ai-observability.default', 'null');
    config()->set('ai-observability.exporters.null.driver', 'null');

    $manager = app(ExporterManager::class);

    expect($manager->exporter())->toBeInstanceOf(NullExporter::class);
});

it('supports custom exporter drivers', function (): void {
    config()->set('ai-observability.exporters.custom.driver', 'custom');

    $manager = app(ExporterManager::class);
    $manager->extend('custom', fn (): Exporter => new class implements Exporter
    {
        public function export(Trace $trace): void {}
    });

    expect($manager->exporter('custom'))->toBeInstanceOf(Exporter::class);
});
```

Create `tests/Feature/LogAndNullExporterTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\LogExporter;
use Tracefast\LaravelAiObservability\Exporters\NullExporter;
use Tracefast\LaravelAiObservability\Exporters\StackExporter;

it('drops traces with the null exporter', function (): void {
    $trace = new Trace('1234567890abcdef1234567890abcdef', 'test', '2026-05-28T10:00:00.000000Z');

    (new NullExporter)->export($trace);

    expect(true)->toBeTrue();
});

it('writes traces to the configured log channel', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('debug')->once()->with('ai-observability.trace', Mockery::type('array'));

    Log::shouldReceive('channel')->with(null)->andReturn($logger);

    $trace = new Trace('1234567890abcdef1234567890abcdef', 'test', '2026-05-28T10:00:00.000000Z');

    (new LogExporter(channel: null, level: 'debug'))->export($trace);
});

it('continues stack export when one exporter fails', function (): void {
    $trace = new Trace('1234567890abcdef1234567890abcdef', 'test', '2026-05-28T10:00:00.000000Z');
    $calls = 0;

    $failing = new class implements Tracefast\LaravelAiObservability\Contracts\Exporter
    {
        public function export(Trace $trace): void
        {
            throw new RuntimeException('failed');
        }
    };

    $working = new class($calls) implements Tracefast\LaravelAiObservability\Contracts\Exporter
    {
        public function __construct(private int &$calls) {}

        public function export(Trace $trace): void
        {
            $this->calls++;
        }
    };

    (new StackExporter([$failing, $working]))->export($trace);

    expect($calls)->toBe(1);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/Feature/ExporterManagerTest.php tests/Feature/LogAndNullExporterTest.php --stop-on-failure
```

Expected: FAIL because exporter classes and contract do not exist.

- [ ] **Step 3: Implement exporter contract and exporters**

Create `src/Contracts/Exporter.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Contracts;

use Tracefast\LaravelAiObservability\Data\Trace;

interface Exporter
{
    public function export(Trace $trace): void;
}
```

Create `src/Exporters/NullExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

class NullExporter implements Exporter
{
    public function export(Trace $trace): void {}
}
```

Create `src/Exporters/LogExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Illuminate\Support\Facades\Log;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

class LogExporter implements Exporter
{
    public function __construct(
        private readonly ?string $channel,
        private readonly string $level,
    ) {}

    public function export(Trace $trace): void
    {
        Log::channel($this->channel)->log($this->level, 'ai-observability.trace', [
            'trace' => $trace->toArray(),
        ]);
    }
}
```

Create `src/Exporters/StackExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

class StackExporter implements Exporter
{
    /**
     * @param  iterable<int, Exporter>  $exporters
     */
    public function __construct(private readonly iterable $exporters) {}

    public function export(Trace $trace): void
    {
        foreach ($this->exporters as $exporter) {
            try {
                $exporter->export($trace);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }
}
```

- [ ] **Step 4: Implement exporter manager and root API**

Create `src/Exporters/ExporterManager.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Tracefast\LaravelAiObservability\Contracts\Exporter;

class ExporterManager
{
    /**
     * @var array<string, Closure>
     */
    private array $customCreators = [];

    public function __construct(private readonly Application $app) {}

    public function exporter(?string $name = null): Exporter
    {
        $name ??= (string) config('ai-observability.default', 'stack');
        $config = config("ai-observability.exporters.{$name}", []);
        $driver = $config['driver'] ?? null;

        return match ($driver) {
            'null' => new NullExporter,
            'log' => new LogExporter($config['channel'] ?? null, $config['level'] ?? 'debug'),
            'stack' => new StackExporter(array_map(
                fn (string $channel): Exporter => $this->exporter($channel),
                $config['channels'] ?? []
            )),
            default => $this->customExporter((string) $driver, $config),
        };
    }

    public function extend(string $driver, Closure $creator): void
    {
        $this->customCreators[$driver] = $creator;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function customExporter(string $driver, array $config): Exporter
    {
        if (isset($this->customCreators[$driver])) {
            return ($this->customCreators[$driver])($this->app, $config);
        }

        throw new InvalidArgumentException("Exporter driver [{$driver}] is not supported.");
    }
}
```

Modify `src/AiObservability.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Closure;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;

class AiObservability
{
    public function __construct(private readonly ExporterManager $exporters) {}

    public function enabled(): bool
    {
        return (bool) config('ai-observability.enabled', false);
    }

    public function exporter(?string $name = null): Exporter
    {
        return $this->exporters->exporter($name);
    }

    public function extend(string $driver, Closure $creator): void
    {
        $this->exporters->extend($driver, $creator);
    }
}
```

Modify `src/LaravelAiObservabilityServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;

class LaravelAiObservabilityServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-observability')
            ->hasConfigFile()
            ->hasMigration('create_ai_observability_tables');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ExporterManager::class, fn ($app): ExporterManager => new ExporterManager($app));
        $this->app->singleton(AiObservability::class, fn ($app): AiObservability => new AiObservability($app->make(ExporterManager::class)));
        $this->app->alias(AiObservability::class, 'ai-observability');
    }
}
```

- [ ] **Step 5: Run exporter tests**

Run:

```bash
vendor/bin/pest tests/Feature/ExporterManagerTest.php tests/Feature/LogAndNullExporterTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit exporters**

Run:

```bash
git add src tests/Feature/ExporterManagerTest.php tests/Feature/LogAndNullExporterTest.php
git commit -m "feat: add exporter manager and base exporters"
```

## Task 4: Database Exporter And Migrations

**Files:**
- Create: `database/migrations/create_ai_observability_tables.php.stub`
- Create: `src/Exporters/DatabaseExporter.php`
- Modify: `src/Exporters/ExporterManager.php`
- Modify: `tests/TestCase.php`
- Create: `tests/Feature/DatabaseExporterTest.php`

- [ ] **Step 1: Write failing database exporter test**

Create `tests/Feature/DatabaseExporterTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\DatabaseExporter;

it('stores trace and span rows', function (): void {
    Schema::create('ai_observability_traces', function ($table): void {
        $table->id();
        $table->string('trace_id')->index();
        $table->string('name');
        $table->string('status');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->float('duration_ms')->nullable();
        $table->timestamp('exported_at')->nullable();
        $table->json('payload');
        $table->timestamps();
    });

    Schema::create('ai_observability_spans', function ($table): void {
        $table->id();
        $table->string('trace_id')->index();
        $table->string('span_id')->index();
        $table->string('parent_span_id')->nullable()->index();
        $table->string('name');
        $table->string('kind');
        $table->string('status');
        $table->timestamp('started_at')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->float('duration_ms')->nullable();
        $table->json('attributes')->nullable();
        $table->json('input')->nullable();
        $table->json('output')->nullable();
        $table->json('payload');
        $table->timestamps();
    });

    $trace = new Trace('1234567890abcdef1234567890abcdef', 'agent run', '2026-05-28T10:00:00.000000Z');
    $trace->addSpan(new Span(
        traceId: $trace->traceId,
        spanId: '1234567890abcdef',
        parentSpanId: null,
        name: 'agent',
        kind: SpanKind::Agent,
        status: SpanStatus::Ok,
        startedAt: '2026-05-28T10:00:00.000000Z',
        endedAt: '2026-05-28T10:00:01.000000Z',
        attributes: ['openinference.span.kind' => 'agent'],
    ));
    $trace->finish('2026-05-28T10:00:01.000000Z', SpanStatus::Ok);

    (new DatabaseExporter(connection: null))->export($trace);

    expect(DB::table('ai_observability_traces')->count())->toBe(1)
        ->and(DB::table('ai_observability_spans')->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/pest tests/Feature/DatabaseExporterTest.php --stop-on-failure
```

Expected: FAIL because `DatabaseExporter` does not exist.

- [ ] **Step 3: Create migration stub**

Create `database/migrations/create_ai_observability_tables.php.stub`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_observability_traces', function (Blueprint $table): void {
            $table->id();
            $table->string('trace_id')->index();
            $table->string('name');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->float('duration_ms')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->json('payload');
            $table->timestamps();
        });

        Schema::create('ai_observability_spans', function (Blueprint $table): void {
            $table->id();
            $table->string('trace_id')->index();
            $table->string('span_id')->index();
            $table->string('parent_span_id')->nullable()->index();
            $table->string('name');
            $table->string('kind');
            $table->string('status');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->float('duration_ms')->nullable();
            $table->json('attributes')->nullable();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('payload');
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 4: Implement database exporter**

Create `src/Exporters/DatabaseExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Illuminate\Support\Facades\DB;
use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

class DatabaseExporter implements Exporter
{
    public function __construct(private readonly ?string $connection) {}

    public function export(Trace $trace): void
    {
        try {
            $database = DB::connection($this->connection);
            $payload = $trace->toArray();

            $database->table('ai_observability_traces')->insert([
                'trace_id' => $payload['trace_id'],
                'name' => $payload['name'],
                'status' => $payload['status'],
                'started_at' => $payload['started_at'],
                'ended_at' => $payload['ended_at'],
                'duration_ms' => $payload['duration_ms'],
                'exported_at' => now(),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($trace->spans() as $span) {
                $spanPayload = $span->toArray();

                $database->table('ai_observability_spans')->insert([
                    'trace_id' => $spanPayload['trace_id'],
                    'span_id' => $spanPayload['span_id'],
                    'parent_span_id' => $spanPayload['parent_span_id'],
                    'name' => $spanPayload['name'],
                    'kind' => $spanPayload['kind'],
                    'status' => $spanPayload['status'],
                    'started_at' => $spanPayload['started_at'],
                    'ended_at' => $spanPayload['ended_at'],
                    'duration_ms' => $spanPayload['duration_ms'],
                    'attributes' => json_encode($spanPayload['attributes'], JSON_THROW_ON_ERROR),
                    'input' => json_encode($spanPayload['input'], JSON_THROW_ON_ERROR),
                    'output' => json_encode($spanPayload['output'], JSON_THROW_ON_ERROR),
                    'payload' => json_encode($spanPayload, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
```

- [ ] **Step 5: Register database driver**

Modify `src/Exporters/ExporterManager.php` so the `match` includes the database driver:

```php
return match ($driver) {
    'null' => new NullExporter,
    'log' => new LogExporter($config['channel'] ?? null, $config['level'] ?? 'debug'),
    'database' => new DatabaseExporter($config['connection'] ?? null),
    'stack' => new StackExporter(array_map(
        fn (string $channel): Exporter => $this->exporter($channel),
        $config['channels'] ?? []
    )),
    default => $this->customExporter((string) $driver, $config),
};
```

- [ ] **Step 6: Run database test**

Run:

```bash
vendor/bin/pest tests/Feature/DatabaseExporterTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit database exporter**

Run:

```bash
git add database src/Exporters tests/Feature/DatabaseExporterTest.php
git commit -m "feat: add database trace exporter"
```

## Task 5: OTLP Endpoint Resolver And Exporter

**Files:**
- Create: `src/Exporters/OtlpEndpoint.php`
- Create: `src/Exporters/OtlpExporter.php`
- Modify: `src/Exporters/ExporterManager.php`
- Create: `tests/Feature/OtlpExporterTest.php`

- [ ] **Step 1: Write failing OTLP tests**

Create `tests/Feature/OtlpExporterTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\OtlpEndpoint;
use Tracefast\LaravelAiObservability\Exporters\OtlpExporter;

it('resolves phoenix preset endpoint', function (): void {
    $endpoint = OtlpEndpoint::fromConfig([
        'preset' => 'phoenix',
        'endpoint' => 'http://localhost:6006',
        'headers' => [],
    ]);

    expect($endpoint->url)->toBe('http://localhost:6006/v1/traces');
});

it('sends OTLP JSON trace payload', function (): void {
    Http::fake([
        'https://collector.example.test/v1/traces' => Http::response('', 200),
    ]);

    $trace = new Trace('1234567890abcdef1234567890abcdef', 'agent run', '2026-05-28T10:00:00.000000Z');
    $trace->addSpan(new Span(
        traceId: $trace->traceId,
        spanId: '1234567890abcdef',
        parentSpanId: null,
        name: 'agent',
        kind: SpanKind::Agent,
        status: SpanStatus::Ok,
        startedAt: '2026-05-28T10:00:00.000000Z',
        endedAt: '2026-05-28T10:00:01.000000Z',
        attributes: ['openinference.span.kind' => 'agent'],
        input: ['value' => 'Hello'],
        output: ['value' => 'Hi'],
    ));
    $trace->finish('2026-05-28T10:00:01.000000Z', SpanStatus::Ok);

    (new OtlpExporter(OtlpEndpoint::fromConfig([
        'endpoint' => 'https://collector.example.test/v1/traces',
        'headers' => ['Authorization' => 'Bearer token'],
    ]), timeout: 1.0))->export($trace);

    Http::assertSent(function ($request): bool {
        $payload = $request->data();

        return $request->url() === 'https://collector.example.test/v1/traces'
            && $request->hasHeader('Authorization', 'Bearer token')
            && isset($payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]);
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/pest tests/Feature/OtlpExporterTest.php --stop-on-failure
```

Expected: FAIL because OTLP classes do not exist.

- [ ] **Step 3: Implement endpoint resolver**

Create `src/Exporters/OtlpEndpoint.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

class OtlpEndpoint
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $endpoint = (string) ($config['endpoint']
            ?: env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT')
            ?: env('OTEL_EXPORTER_OTLP_ENDPOINT')
            ?: 'http://localhost:4318/v1/traces');

        $endpoint = self::normalizeTraceEndpoint($endpoint);

        return new self(
            url: $endpoint,
            headers: self::headers($config['headers'] ?? []),
        );
    }

    private static function normalizeTraceEndpoint(string $endpoint): string
    {
        $endpoint = rtrim($endpoint, '/');

        if (str_ends_with($endpoint, '/v1/traces')) {
            return $endpoint;
        }

        return "{$endpoint}/v1/traces";
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private static function headers(array $headers): array
    {
        $resolved = [];

        foreach ($headers as $name => $value) {
            if ($value !== null && $value !== '') {
                $resolved[(string) $name] = (string) $value;
            }
        }

        foreach ([env('OTEL_EXPORTER_OTLP_TRACES_HEADERS'), env('OTEL_EXPORTER_OTLP_HEADERS')] as $headerLine) {
            if (! is_string($headerLine) || $headerLine === '') {
                continue;
            }

            foreach (explode(',', $headerLine) as $header) {
                [$name, $value] = array_pad(explode('=', $header, 2), 2, null);

                if ($name !== null && $value !== null) {
                    $resolved[trim($name)] = trim($value);
                }
            }
        }

        return $resolved;
    }
}
```

- [ ] **Step 4: Implement OTLP JSON exporter**

Create `src/Exporters/OtlpExporter.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Illuminate\Support\Facades\Http;
use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\Trace;

class OtlpExporter implements Exporter
{
    public function __construct(
        private readonly OtlpEndpoint $endpoint,
        private readonly float $timeout,
    ) {}

    public function export(Trace $trace): void
    {
        try {
            Http::withHeaders([
                'Content-Type' => 'application/json',
                ...$this->endpoint->headers,
            ])
                ->timeout($this->timeout)
                ->post($this->endpoint->url, $this->payload($trace))
                ->throw();
        } catch (Throwable $exception) {
            report($exception);
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
                            $this->attribute('service.name', config('app.name', 'laravel')),
                            $this->attribute('telemetry.sdk.name', 'tracefast-laravel-ai-observability'),
                            $this->attribute('telemetry.sdk.language', 'php'),
                        ],
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'tracefast/laravel-ai-observability',
                            ],
                            'spans' => array_map(fn (Span $span): array => $this->spanPayload($span), $trace->spans()),
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

        return [
            'traceId' => $payload['trace_id'],
            'spanId' => $payload['span_id'],
            'parentSpanId' => $payload['parent_span_id'],
            'name' => $payload['name'],
            'kind' => 1,
            'startTimeUnixNano' => $this->unixNano($payload['started_at']),
            'endTimeUnixNano' => $payload['ended_at'] ? $this->unixNano($payload['ended_at']) : null,
            'attributes' => array_values(array_filter([
                ...array_map(fn (string $key, mixed $value): array => $this->attribute($key, $value), array_keys($payload['attributes']), $payload['attributes']),
                $this->attribute('openinference.span.kind', $payload['kind']),
                $payload['input'] !== null ? $this->attribute('input.value', json_encode($payload['input'], JSON_THROW_ON_ERROR)) : null,
                $payload['output'] !== null ? $this->attribute('output.value', json_encode($payload['output'], JSON_THROW_ON_ERROR)) : null,
            ])),
            'status' => [
                'code' => $payload['status'] === 'error' ? 2 : 1,
                'message' => $payload['error_message'] ?? '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attribute(string $key, mixed $value): array
    {
        return [
            'key' => $key,
            'value' => is_numeric($value)
                ? ['doubleValue' => (float) $value]
                : ['stringValue' => (string) $value],
        ];
    }

    private function unixNano(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        $seconds = strtotime($timestamp);

        if ($seconds === false) {
            return null;
        }

        return (string) ($seconds * 1_000_000_000);
    }
}
```

- [ ] **Step 5: Register OTLP driver**

Modify `src/Exporters/ExporterManager.php` so the `match` includes the OTLP driver:

```php
return match ($driver) {
    'null' => new NullExporter,
    'log' => new LogExporter($config['channel'] ?? null, $config['level'] ?? 'debug'),
    'database' => new DatabaseExporter($config['connection'] ?? null),
    'otlp' => new OtlpExporter(OtlpEndpoint::fromConfig($config), (float) config('ai-observability.export.timeout', 2.0)),
    'stack' => new StackExporter(array_map(
        fn (string $channel): Exporter => $this->exporter($channel),
        $config['channels'] ?? []
    )),
    default => $this->customExporter((string) $driver, $config),
};
```

- [ ] **Step 6: Run OTLP tests**

Run:

```bash
vendor/bin/pest tests/Feature/OtlpExporterTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit OTLP exporter**

Run:

```bash
git add src/Exporters tests/Feature/OtlpExporterTest.php
git commit -m "feat: add otlp trace exporter"
```

## Task 6: Laravel AI Event Registry And Mapper

**Files:**
- Create: `src/Contracts/TraceRegistry.php`
- Create: `src/Registry/InMemoryTraceRegistry.php`
- Create: `src/LaravelAi/EventClassMap.php`
- Create: `src/LaravelAi/LaravelAiEventMapper.php`
- Create: `tests/Fixtures/FakeEvents.php`
- Create: `tests/Feature/LaravelAiEventMapperTest.php`

- [ ] **Step 1: Write failing mapper tests**

Create `tests/Fixtures/FakeEvents.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Tests\Fixtures;

class FakePromptingAgent
{
    public function __construct(
        public string $invocationId,
        public object $prompt,
    ) {}
}

class FakeAgentPrompted
{
    public function __construct(
        public string $invocationId,
        public object $prompt,
        public object $response,
    ) {}
}

class FakeInvokingTool
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public object $agent,
        public object $tool,
        public array $arguments,
    ) {}
}

class FakeToolInvoked
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $invocationId,
        public string $toolInvocationId,
        public object $agent,
        public object $tool,
        public array $arguments,
        public mixed $result,
    ) {}
}
```

Create `tests/Feature/LaravelAiEventMapperTest.php`:

```php
<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventMapper;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeAgentPrompted;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeInvokingTool;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptingAgent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeToolInvoked;

it('extracts prompt lifecycle fields defensively', function (): void {
    $mapper = new LaravelAiEventMapper;

    $prompt = (object) [
        'prompt' => 'Write a welcome message',
        'provider' => 'openai',
        'model' => 'gpt-4.1',
    ];

    $mapped = $mapper->prompting(new FakePromptingAgent('inv_123', $prompt));

    expect($mapped)->toMatchArray([
        'invocation_id' => 'inv_123',
        'input' => ['value' => 'Write a welcome message'],
        'attributes' => [
            'tracefast.ai.invocation_id' => 'inv_123',
        ],
    ]);
});

it('extracts response usage and output', function (): void {
    $mapper = new LaravelAiEventMapper;

    $response = (object) [
        'text' => 'Welcome.',
        'usage' => (object) [
            'promptTokens' => 10,
            'completionTokens' => 4,
        ],
        'meta' => (object) [
            'provider' => 'openai',
            'model' => 'gpt-4.1',
        ],
    ];

    $mapped = $mapper->prompted(new FakeAgentPrompted('inv_123', (object) ['prompt' => 'Hello'], $response));

    expect($mapped['output'])->toBe(['value' => 'Welcome.'])
        ->and($mapped['attributes']['llm.token_count.prompt'])->toBe(10)
        ->and($mapped['attributes']['llm.token_count.completion'])->toBe(4);
});

it('extracts tool arguments and result', function (): void {
    $mapper = new LaravelAiEventMapper;
    $tool = new class
    {
        public function name(): string
        {
            return 'search_jobs';
        }
    };

    $invoking = $mapper->invokingTool(new FakeInvokingTool('inv_123', 'tool_1', (object) [], $tool, ['q' => 'php']));
    $invoked = $mapper->toolInvoked(new FakeToolInvoked('inv_123', 'tool_1', (object) [], $tool, ['q' => 'php'], ['count' => 2]));

    expect($invoking['attributes']['tool.name'])->toBe('search_jobs')
        ->and($invoking['input'])->toBe(['value' => ['q' => 'php']])
        ->and($invoked['output'])->toBe(['value' => ['count' => 2]]);
});
```

- [ ] **Step 2: Run mapper tests to verify they fail**

Run:

```bash
vendor/bin/pest tests/Feature/LaravelAiEventMapperTest.php --stop-on-failure
```

Expected: FAIL because mapper classes do not exist.

- [ ] **Step 3: Implement registry contract and in-memory registry**

Create `src/Contracts/TraceRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Contracts;

use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\Trace;

interface TraceRegistry
{
    public function start(string $invocationId, Trace $trace, Span $rootSpan): void;

    public function trace(string $invocationId): ?Trace;

    public function rootSpan(string $invocationId): ?Span;

    public function forget(string $invocationId): void;
}
```

Create `src/Registry/InMemoryTraceRegistry.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Registry;

use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\Trace;

class InMemoryTraceRegistry implements TraceRegistry
{
    /**
     * @var array<string, array{trace: Trace, root: Span}>
     */
    private array $items = [];

    public function start(string $invocationId, Trace $trace, Span $rootSpan): void
    {
        $trace->addSpan($rootSpan);

        $this->items[$invocationId] = [
            'trace' => $trace,
            'root' => $rootSpan,
        ];
    }

    public function trace(string $invocationId): ?Trace
    {
        return $this->items[$invocationId]['trace'] ?? null;
    }

    public function rootSpan(string $invocationId): ?Span
    {
        return $this->items[$invocationId]['root'] ?? null;
    }

    public function forget(string $invocationId): void
    {
        unset($this->items[$invocationId]);
    }
}
```

- [ ] **Step 4: Implement event class map**

Create `src/LaravelAi/EventClassMap.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

class EventClassMap
{
    /**
     * @return array<string, string>
     */
    public static function events(): array
    {
        return array_filter([
            'prompting' => class_exists('Laravel\\Ai\\Events\\PromptingAgent') ? 'Laravel\\Ai\\Events\\PromptingAgent' : null,
            'prompted' => class_exists('Laravel\\Ai\\Events\\AgentPrompted') ? 'Laravel\\Ai\\Events\\AgentPrompted' : null,
            'streaming' => class_exists('Laravel\\Ai\\Events\\StreamingAgent') ? 'Laravel\\Ai\\Events\\StreamingAgent' : null,
            'streamed' => class_exists('Laravel\\Ai\\Events\\AgentStreamed') ? 'Laravel\\Ai\\Events\\AgentStreamed' : null,
            'invoking_tool' => class_exists('Laravel\\Ai\\Events\\InvokingTool') ? 'Laravel\\Ai\\Events\\InvokingTool' : null,
            'tool_invoked' => class_exists('Laravel\\Ai\\Events\\ToolInvoked') ? 'Laravel\\Ai\\Events\\ToolInvoked' : null,
        ]);
    }
}
```

- [ ] **Step 5: Implement event mapper**

Create `src/LaravelAi/LaravelAiEventMapper.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

class LaravelAiEventMapper
{
    /**
     * @return array<string, mixed>
     */
    public function prompting(object $event): array
    {
        $invocationId = $this->value($event, 'invocationId');
        $prompt = $this->value($event, 'prompt');

        return [
            'invocation_id' => $invocationId,
            'name' => $this->agentName($event),
            'input' => ['value' => $this->value($prompt, 'prompt')],
            'attributes' => [
                'openinference.span.kind' => 'agent',
                'tracefast.ai.invocation_id' => $invocationId,
                'llm.provider' => $this->value($prompt, 'provider'),
                'llm.model_name' => $this->value($prompt, 'model'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prompted(object $event): array
    {
        $response = $this->value($event, 'response');
        $usage = $this->value($response, 'usage');
        $meta = $this->value($response, 'meta');

        return [
            'invocation_id' => $this->value($event, 'invocationId'),
            'output' => ['value' => $this->responseText($response)],
            'attributes' => [
                'llm.provider' => $this->value($meta, 'provider'),
                'llm.model_name' => $this->value($meta, 'model'),
                'llm.token_count.prompt' => $this->value($usage, 'promptTokens'),
                'llm.token_count.completion' => $this->value($usage, 'completionTokens'),
                'tracefast.ai.conversation_id' => $this->value($response, 'conversationId'),
                'tracefast.ai.response_type' => $response ? $response::class : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invokingTool(object $event): array
    {
        return [
            'invocation_id' => $this->value($event, 'invocationId'),
            'tool_invocation_id' => $this->value($event, 'toolInvocationId'),
            'name' => $this->toolName($this->value($event, 'tool')),
            'input' => ['value' => $this->value($event, 'arguments')],
            'attributes' => [
                'openinference.span.kind' => 'tool',
                'tool.name' => $this->toolName($this->value($event, 'tool')),
                'tool.call.id' => $this->value($event, 'toolInvocationId'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toolInvoked(object $event): array
    {
        return [
            'invocation_id' => $this->value($event, 'invocationId'),
            'tool_invocation_id' => $this->value($event, 'toolInvocationId'),
            'output' => ['value' => $this->value($event, 'result')],
        ];
    }

    private function agentName(object $event): string
    {
        $agent = $this->value($event, 'agent');

        return is_object($agent) ? class_basename($agent::class) : 'Laravel AI Agent';
    }

    private function toolName(mixed $tool): string
    {
        if (is_object($tool) && method_exists($tool, 'name')) {
            return (string) $tool->name();
        }

        return is_object($tool) ? class_basename($tool::class) : 'tool';
    }

    private function responseText(mixed $response): mixed
    {
        if (is_object($response) && property_exists($response, 'text')) {
            return $response->text;
        }

        if (is_object($response) && method_exists($response, '__toString')) {
            return (string) $response;
        }

        return null;
    }

    private function value(mixed $target, string $name): mixed
    {
        if (! is_object($target)) {
            return null;
        }

        if (property_exists($target, $name)) {
            return $target->{$name};
        }

        if (method_exists($target, $name)) {
            return $target->{$name}();
        }

        return null;
    }
}
```

- [ ] **Step 6: Run mapper tests**

Run:

```bash
vendor/bin/pest tests/Feature/LaravelAiEventMapperTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit mapper and registry**

Run:

```bash
git add src/Contracts src/Registry src/LaravelAi tests/Fixtures tests/Feature/LaravelAiEventMapperTest.php
git commit -m "feat: add laravel ai event mapper"
```

## Task 7: Laravel AI Event Subscriber Integration

**Files:**
- Create: `src/LaravelAi/LaravelAiEventSubscriber.php`
- Modify: `src/LaravelAi/EventClassMap.php`
- Modify: `src/LaravelAi/LaravelAiEventMapper.php`
- Modify: `src/LaravelAiObservabilityServiceProvider.php`
- Modify: `src/Data/Span.php`
- Create: `tests/Feature/LaravelAiEventSubscriberTest.php`

- [ ] **Step 1: Write failing subscriber test**

Create `tests/Feature/LaravelAiEventSubscriberTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventSubscriber;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeAgentPrompted;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeInvokingTool;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptingAgent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeToolInvoked;

it('builds and exports a trace for an agent lifecycle', function (): void {
    config()->set('ai-observability.enabled', true);
    config()->set('ai-observability.default', 'log');

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('log')->once()->with('debug', 'ai-observability.trace', Mockery::on(
        fn (array $context): bool => ($context['trace']['spans'][0]['attributes']['tracefast.ai.invocation_id'] ?? null) === 'inv_123'
    ));

    Log::shouldReceive('channel')->with(null)->andReturn($logger);

    $subscriber = app(LaravelAiEventSubscriber::class);
    $subscriber->handlePrompting(new FakePromptingAgent('inv_123', (object) ['prompt' => 'Hello']));
    $subscriber->handleInvokingTool(new FakeInvokingTool('inv_123', 'tool_1', (object) [], new class
    {
        public function name(): string
        {
            return 'search';
        }
    }, ['query' => 'php']));
    $subscriber->handleToolInvoked(new FakeToolInvoked('inv_123', 'tool_1', (object) [], new class
    {
        public function name(): string
        {
            return 'search';
        }
    }, ['query' => 'php'], ['result' => 'ok']));
    $subscriber->handlePrompted(new FakeAgentPrompted('inv_123', (object) ['prompt' => 'Hello'], (object) ['text' => 'Hi']));

    expect(app(TraceRegistry::class)->trace('inv_123'))->toBeNull();
});

it('does nothing when disabled', function (): void {
    config()->set('ai-observability.enabled', false);

    $subscriber = app(LaravelAiEventSubscriber::class);
    $subscriber->handlePrompting(new FakePromptingAgent('inv_123', (object) ['prompt' => 'Hello']));

    expect(app(TraceRegistry::class)->trace('inv_123'))->toBeNull();
});
```

- [ ] **Step 2: Run subscriber test to verify it fails**

Run:

```bash
vendor/bin/pest tests/Feature/LaravelAiEventSubscriberTest.php --stop-on-failure
```

Expected: FAIL because subscriber bindings do not exist.

- [ ] **Step 3: Implement subscriber**

Create `src/LaravelAi/LaravelAiEventSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

use Illuminate\Events\Dispatcher;
use Throwable;
use Tracefast\LaravelAiObservability\AiObservability;
use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Support\Clock;
use Tracefast\LaravelAiObservability\Support\Ids;

class LaravelAiEventSubscriber
{
    /**
     * @var array<string, Span>
     */
    private array $toolSpans = [];

    public function __construct(
        private readonly AiObservability $observability,
        private readonly TraceRegistry $registry,
        private readonly LaravelAiEventMapper $mapper,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        foreach (EventClassMap::events() as $key => $class) {
            $method = match ($key) {
                'prompting', 'streaming' => 'handlePrompting',
                'prompted', 'streamed' => 'handlePrompted',
                'invoking_tool' => 'handleInvokingTool',
                'tool_invoked' => 'handleToolInvoked',
            };

            $events->listen($class, [self::class, $method]);
        }
    }

    public function handlePrompting(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $mapped = $this->mapper->prompting($event);
        $invocationId = (string) $mapped['invocation_id'];
        $startedAt = Clock::now();
        $traceId = Ids::traceId();

        $trace = new Trace($traceId, (string) $mapped['name'], $startedAt);
        $rootSpan = new Span(
            traceId: $traceId,
            spanId: Ids::spanId(),
            parentSpanId: null,
            name: (string) $mapped['name'],
            kind: SpanKind::Agent,
            status: SpanStatus::Unset,
            startedAt: $startedAt,
            attributes: $mapped['attributes'],
            input: $mapped['input'],
        );

        $this->registry->start($invocationId, $trace, $rootSpan);
    }

    public function handleInvokingTool(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $mapped = $this->mapper->invokingTool($event);
        $trace = $this->registry->trace((string) $mapped['invocation_id']);
        $rootSpan = $this->registry->rootSpan((string) $mapped['invocation_id']);

        if ($trace === null || $rootSpan === null) {
            return;
        }

        $span = new Span(
            traceId: $trace->traceId,
            spanId: Ids::spanId(),
            parentSpanId: $rootSpan->spanId,
            name: (string) $mapped['name'],
            kind: SpanKind::Tool,
            status: SpanStatus::Unset,
            startedAt: Clock::now(),
            attributes: $mapped['attributes'],
            input: $mapped['input'],
        );

        $this->toolSpans[(string) $mapped['tool_invocation_id']] = $span;
        $trace->addSpan($span);
    }

    public function handleToolInvoked(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $mapped = $this->mapper->toolInvoked($event);
        $toolInvocationId = (string) $mapped['tool_invocation_id'];
        $span = $this->toolSpans[$toolInvocationId] ?? null;

        if ($span === null) {
            return;
        }

        $span->output = $mapped['output'];
        $span->finish(Clock::now(), SpanStatus::Ok);
        unset($this->toolSpans[$toolInvocationId]);
    }

    public function handlePrompted(object $event): void
    {
        if (! $this->observability->enabled()) {
            return;
        }

        $mapped = $this->mapper->prompted($event);
        $invocationId = (string) $mapped['invocation_id'];
        $trace = $this->registry->trace($invocationId);
        $rootSpan = $this->registry->rootSpan($invocationId);

        if ($trace === null || $rootSpan === null) {
            return;
        }

        $rootSpan->output = $mapped['output'];
        $rootSpan->attributes = array_filter([
            ...$rootSpan->attributes,
            ...$mapped['attributes'],
        ], fn (mixed $value): bool => $value !== null);
        $rootSpan->finish(Clock::now(), SpanStatus::Ok);
        $trace->finish(Clock::now(), SpanStatus::Ok);

        try {
            $this->observability->exporter()->export($trace);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $this->registry->forget($invocationId);
        }
    }
}
```

- [ ] **Step 4: Bind registry, mapper, and subscriber**

Modify `src/LaravelAiObservabilityServiceProvider.php`:

```php
use Illuminate\Support\Facades\Event;
use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventMapper;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventSubscriber;
use Tracefast\LaravelAiObservability\Registry\InMemoryTraceRegistry;
```

Add these bindings inside `packageRegistered()`:

```php
$this->app->singleton(TraceRegistry::class, fn (): TraceRegistry => new InMemoryTraceRegistry);
$this->app->singleton(LaravelAiEventMapper::class, fn (): LaravelAiEventMapper => new LaravelAiEventMapper);
$this->app->singleton(LaravelAiEventSubscriber::class);
```

Add this method:

```php
public function packageBooted(): void
{
    Event::subscribe(LaravelAiEventSubscriber::class);
}
```

- [ ] **Step 5: Run subscriber tests**

Run:

```bash
vendor/bin/pest tests/Feature/LaravelAiEventSubscriberTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit subscriber**

Run:

```bash
git add src tests/Feature/LaravelAiEventSubscriberTest.php
git commit -m "feat: observe laravel ai lifecycle events"
```

## Task 8: Content Capture Completeness

**Files:**
- Modify: `src/LaravelAi/LaravelAiEventMapper.php`
- Create: `tests/Feature/ContentCaptureTest.php`

- [ ] **Step 1: Write failing content capture test**

Create `tests/Feature/ContentCaptureTest.php`:

```php
<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventMapper;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeAgentPrompted;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptingAgent;

it('captures full messages steps tool calls and tool results when enabled', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $mapper = new LaravelAiEventMapper;
    $prompt = (object) [
        'prompt' => 'Find candidates',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a recruiter.'],
            ['role' => 'user', 'content' => 'Find PHP candidates.'],
        ],
    ];
    $response = (object) [
        'text' => 'Found two candidates.',
        'toolCalls' => [
            ['id' => 'tool_1', 'name' => 'search_candidates', 'arguments' => ['skill' => 'php']],
        ],
        'toolResults' => [
            ['id' => 'tool_1', 'result' => ['count' => 2]],
        ],
        'steps' => [
            ['finishReason' => 'tool_calls'],
            ['finishReason' => 'stop'],
        ],
    ];

    $prompting = $mapper->prompting(new FakePromptingAgent('inv_123', $prompt));
    $prompted = $mapper->prompted(new FakeAgentPrompted('inv_123', $prompt, $response));

    expect($prompting['input']['messages'])->toHaveCount(2)
        ->and($prompted['output']['tool_calls'])->toHaveCount(1)
        ->and($prompted['output']['tool_results'])->toHaveCount(1)
        ->and($prompted['output']['steps'])->toHaveCount(2);
});

it('can disable content capture', function (): void {
    config()->set('ai-observability.capture.content', 'off');

    $mapper = new LaravelAiEventMapper;
    $mapped = $mapper->prompting(new FakePromptingAgent('inv_123', (object) ['prompt' => 'secret']));

    expect($mapped['input'])->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
vendor/bin/pest tests/Feature/ContentCaptureTest.php --stop-on-failure
```

Expected: FAIL because mapper does not capture full message/tool/step payloads and does not honor `off`.

- [ ] **Step 3: Update mapper content handling**

Modify `src/LaravelAi/LaravelAiEventMapper.php` by adding these methods:

```php
private function captureContent(): bool
{
    return config('ai-observability.capture.content', 'full') === 'full';
}

/**
 * @return array<string, mixed>|null
 */
private function promptInput(mixed $prompt): ?array
{
    if (! $this->captureContent()) {
        return null;
    }

    return [
        'value' => $this->value($prompt, 'prompt'),
        'messages' => $this->arrayValue($this->value($prompt, 'messages')),
    ];
}

/**
 * @return array<string, mixed>|null
 */
private function responseOutput(mixed $response): ?array
{
    if (! $this->captureContent()) {
        return null;
    }

    return [
        'value' => $this->responseText($response),
        'tool_calls' => $this->arrayValue($this->value($response, 'toolCalls')),
        'tool_results' => $this->arrayValue($this->value($response, 'toolResults')),
        'steps' => $this->arrayValue($this->value($response, 'steps')),
    ];
}

/**
 * @return array<int, mixed>
 */
private function arrayValue(mixed $value): array
{
    if ($value instanceof \Traversable) {
        return iterator_to_array($value);
    }

    return is_array($value) ? $value : [];
}
```

Change `prompting()` so `input` uses:

```php
'input' => $this->promptInput($prompt),
```

Change `prompted()` so `output` uses:

```php
'output' => $this->responseOutput($response),
```

Change `invokingTool()` so `input` uses:

```php
'input' => $this->captureContent() ? ['value' => $this->value($event, 'arguments')] : null,
```

Change `toolInvoked()` so `output` uses:

```php
'output' => $this->captureContent() ? ['value' => $this->value($event, 'result')] : null,
```

- [ ] **Step 4: Run content capture tests**

Run:

```bash
vendor/bin/pest tests/Feature/ContentCaptureTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit content capture**

Run:

```bash
git add src/LaravelAi/LaravelAiEventMapper.php tests/Feature/ContentCaptureTest.php
git commit -m "feat: capture full laravel ai content"
```

## Task 9: README And Open Source Polish

**Files:**
- Create: `README.md`
- Modify: `composer.json`

- [ ] **Step 1: Write README**

Create `README.md`:

```markdown
# Laravel AI Observability

OpenInference observability for the Laravel AI SDK.

`tracefast/laravel-ai-observability` listens to Laravel AI SDK events and exports agent traces/spans to logs, OTLP receivers, an optional database, or multiple destinations at once.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `laravel/ai:^0.7`

## Install

```bash
composer require tracefast/laravel-ai-observability
```

Publish the config:

```bash
php artisan vendor:publish --tag=ai-observability-config
```

Enable tracing:

```env
AI_OBSERVABILITY_ENABLED=true
AI_OBSERVABILITY_EXPORTER=stack
```

By default, the stack exporter writes structured traces to the log exporter.

## Content Capture Warning

V1 captures full LLM input/output by default. This can include prompts, system messages, tool arguments, tool results, uploaded content, PII, secrets, and business data.

Disable content capture when needed:

```env
AI_OBSERVABILITY_CAPTURE_CONTENT=off
```

## Exporters

### Log

```env
AI_OBSERVABILITY_EXPORTER=log
AI_OBSERVABILITY_LOG_LEVEL=debug
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
LANGFUSE_OTEL_AUTHORIZATION="Basic base64-public-secret"
```

### Braintrust

```env
AI_OBSERVABILITY_EXPORTER=braintrust
BRAINTRUST_API_KEY=your-api-key
BRAINTRUST_PARENT=project_id:your-project-id
```

### Stack

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['log', 'phoenix'],
],
```

### Database

Publish and run the migration:

```bash
php artisan vendor:publish --tag=ai-observability-migrations
php artisan migrate
```

Then configure:

```env
AI_OBSERVABILITY_EXPORTER=database
```

## Custom Exporters

```php
use Tracefast\LaravelAiObservability\Facades\AiObservability;

AiObservability::extend('custom', fn ($app, array $config) => new CustomExporter);
```

Custom exporters implement:

```php
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

class CustomExporter implements Exporter
{
    public function export(Trace $trace): void
    {
        logger()->debug('custom ai trace', ['trace' => $trace->toArray()]);
    }
}
```

## Testing

```bash
composer test
```

## License

MIT
```

- [ ] **Step 2: Add README metadata if Packagist expects it**

Confirm `composer.json` already has:

```json
"license": "MIT",
"keywords": [
    "laravel",
    "laravel-ai",
    "openinference",
    "opentelemetry",
    "otlp",
    "phoenix",
    "langfuse",
    "braintrust",
    "observability"
]
```

- [ ] **Step 3: Run README-related checks**

Run:

```bash
composer validate --strict
```

Expected: PASS.

- [ ] **Step 4: Commit README**

Run:

```bash
git add README.md composer.json
git commit -m "docs: add package readme"
```

## Task 10: Final Formatting And Verification

**Files:**
- Modify: any PHP files changed by Pint.

- [ ] **Step 1: Run Pint**

Run:

```bash
vendor/bin/pint --format agent
```

Expected: PASS and either no files changed or only formatting changes.

- [ ] **Step 2: Run full package tests**

Run:

```bash
vendor/bin/pest
```

Expected: PASS.

- [ ] **Step 3: Run Composer validation**

Run:

```bash
composer validate --strict
```

Expected: PASS.

- [ ] **Step 4: Inspect git diff**

Run:

```bash
git status --short
git diff --stat
```

Expected: no unexpected files and only intended package implementation changes.

- [ ] **Step 5: Commit final formatting changes if Pint changed files**

If `git status --short` shows formatting changes, run:

```bash
git add .
git commit -m "style: format package"
```

Expected: commit succeeds. If there are no formatting changes, do not create an empty commit.

## Self-Review Checklist

- Spec coverage: The plan covers package scaffold, OpenInference model, exporter manager, log/null/stack/database/OTLP exporters, Laravel AI compatibility adapter, full content capture, docs, tests, and final verification.
- V1 scope check: No PostHog exporter, no metrics, no scores/evals table, no dashboard, no prompt registry, no replay system, no automatic LLM-as-judge, and no redaction engine.
- Type consistency: Exporters implement `Tracefast\LaravelAiObservability\Contracts\Exporter`; traces use `Tracefast\LaravelAiObservability\Data\Trace`; spans use `Tracefast\LaravelAiObservability\Data\Span`.
- Test strategy: Each implementation task starts with a failing Pest test, then adds minimum code, then verifies passing tests.
