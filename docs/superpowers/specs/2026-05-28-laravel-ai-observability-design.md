# Laravel AI Observability Package Design

Date: 2026-05-28

Package: `tracefast/laravel-ai-observability`

Namespace: `Tracefast\LaravelAiObservability`

Target path: `/Users/rajuljain/Desktop/work/sites/laravel/laravel-ai-observability`

## Purpose

Build a standalone, open-source Laravel package that adds observability for `laravel/ai` agent runs without changing application code or interfering with LLM execution. The package captures Laravel AI SDK events, maps them into OpenInference-style traces and spans, and exports them through Laravel-style exporter channels.

The package should let developers inspect full agent conversations and tool activity in receivers such as Phoenix, Langfuse, Braintrust, logs, or an optional local database.

## Non-Goals For V1

- No PostHog exporter.
- No metrics pipeline.
- No evaluation or score tables.
- No dashboard UI.
- No prompt registry.
- No local replay system.
- No automatic LLM-as-judge calls.
- No redaction engine in V1.
- No broad HTTP, queue, database, or application auto-instrumentation outside `laravel/ai`.

## Compatibility

V1 targets:

- PHP `^8.4`
- Laravel `^12.0|^13.0`
- `laravel/ai:^0.7`
- `spatie/laravel-package-tools:^1.16`
- Pest for package-local tests
- Orchestra Testbench for Laravel package testing

The package should isolate all `laravel/ai` assumptions in one compatibility layer. It should listen to documented SDK events first, guard optional classes with `class_exists()`, and fail closed if future SDK versions change event availability.

## Architecture

The package has four layers:

```text
Laravel AI SDK events
        |
Compatibility adapter
        |
OpenInference trace/span model
        |
Exporter manager
        |
log / otlp / database / null / stack
```

### Compatibility Adapter

The adapter listens to Laravel AI SDK events and converts them into package-neutral lifecycle messages.

V1 should listen to these events when present:

- `PromptingAgent`
- `AgentPrompted`
- `StreamingAgent`
- `AgentStreamed`
- `InvokingTool`
- `ToolInvoked`
- Embedding, rerank, file, image, audio, and transcription events where useful and stable

The adapter is the only layer that should reference Laravel AI event classes directly.

### OpenInference Model

OpenInference is the primary semantic schema. OTLP is the transport.

The internal model should represent:

- Trace
- Span
- Span attributes
- Span input/output payloads
- Parent-child relationships
- Status and error details
- Timing data

Primary span types:

- Agent or chain span for each agent turn/run
- LLM span for model calls
- Tool span for app-executed tools
- Embedding span
- Retriever or reranker span when the SDK exposes enough data

Package-specific details should use namespaced attributes, for example:

- `tracefast.ai.invocation_id`
- `tracefast.ai.conversation_id`
- `tracefast.ai.sdk_version`
- `tracefast.ai.response_type`

## Data Flow

The package builds a trace in memory keyed by Laravel AI `invocationId`.

```text
PromptingAgent / StreamingAgent
  -> create trace
  -> create root agent span

InvokingTool
  -> create child tool span

ToolInvoked
  -> finish child tool span

AgentPrompted / AgentStreamed
  -> attach provider, model, usage, output, steps, and content
  -> finish root span
  -> export through configured exporter stack
```

For streaming, V1 should capture final streamed output, usage, stream status, and any timing data the SDK exposes reliably. It should not create one span per chunk. If first-token timing is not reliably available from SDK events, V1 should not invent it.

## Content Capture

V1 defaults to full content capture.

When Laravel AI exposes the data, V1 should capture:

- Input messages and prompt text
- Output text or structured output
- Tool call arguments
- Tool results
- Embedding and rerank inputs where available
- Provider/model metadata
- Token usage
- SDK invocation and conversation identifiers

V1 does not include built-in redaction. Documentation must clearly warn that full capture may include PII, secrets, system prompts, uploaded content, tool outputs, and sensitive business data. Users can disable content capture through config if needed.

## Exporter Configuration

Configuration should feel like Laravel logging.

```php
return [
    'enabled' => env('AI_OBSERVABILITY_ENABLED', false),

    'default' => env('AI_OBSERVABILITY_EXPORTER', 'stack'),

    'capture' => [
        'content' => env('AI_OBSERVABILITY_CAPTURE_CONTENT', 'full'),
    ],

    'exporters' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['log'],
        ],

        'log' => [
            'driver' => 'log',
            'channel' => env('AI_OBSERVABILITY_LOG_CHANNEL'),
            'level' => 'debug',
        ],

        'phoenix' => [
            'driver' => 'otlp',
            'preset' => 'phoenix',
            'endpoint' => env('PHOENIX_COLLECTOR_ENDPOINT', 'http://localhost:6006/v1/traces'),
        ],

        'langfuse' => [
            'driver' => 'otlp',
            'preset' => 'langfuse',
            'endpoint' => env('LANGFUSE_OTEL_ENDPOINT'),
            'headers' => [
                'Authorization' => env('LANGFUSE_OTEL_AUTHORIZATION'),
            ],
        ],

        'braintrust' => [
            'driver' => 'otlp',
            'preset' => 'braintrust',
            'endpoint' => env('BRAINTRUST_OTEL_ENDPOINT', 'https://api.braintrust.dev/otel/v1/traces'),
            'headers' => [
                'Authorization' => env('BRAINTRUST_API_KEY') ? 'Bearer '.env('BRAINTRUST_API_KEY') : null,
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

The default exporter is `stack`, and the default stack writes to `log`. This provides a safe install path with no network calls and no database migrations unless users opt in.

## Exporters

### Stack Exporter

Sends each trace to multiple named exporters. It should attempt every configured exporter and ensure one exporter failure does not prevent other exporters from running.

### Log Exporter

Writes structured trace and span payloads to a Laravel log channel. This is the V1 default and the easiest debugging path.

### OTLP Exporter

Sends OpenInference-compatible traces over OTLP HTTP to receivers.

Presets are convenience defaults for OTLP receivers, not separate integration layers:

- `generic`
- `phoenix`
- `langfuse`
- `braintrust`

The OTLP exporter should support:

- `OTEL_EXPORTER_OTLP_ENDPOINT`
- `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`
- `OTEL_EXPORTER_OTLP_HEADERS`
- `OTEL_EXPORTER_OTLP_TRACES_HEADERS`
- Explicit endpoint and headers from package config
- Short request timeouts
- Deferred or queued export modes where possible

### Database Exporter

Opt-in local storage. It stores only traces and spans:

- `ai_observability_traces`
- `ai_observability_spans`

It does not store scores, metrics, datasets, or evaluations in V1.

### Null Exporter

Drops traces. Useful for tests, disabled environments, and fallback behavior.

## Database Schema

### `ai_observability_traces`

Recommended columns:

- `id`
- `trace_id`
- `name`
- `status`
- `started_at`
- `ended_at`
- `duration_ms`
- `exported_at`
- `payload`
- timestamps

### `ai_observability_spans`

Recommended columns:

- `id`
- `trace_id`
- `span_id`
- `parent_span_id`
- `name`
- `kind`
- `status`
- `started_at`
- `ended_at`
- `duration_ms`
- `attributes`
- `input`
- `output`
- `payload`
- timestamps

JSON columns should preserve the OpenInference payload without over-normalizing V1.

## Public API

V1 should keep the public API small:

```php
AiObservability::enabled();
AiObservability::exporter('phoenix');
AiObservability::extend('custom', fn ($app, array $config) => new CustomExporter);
```

Most users should not call the API manually. Install, enable, configure exporters, then run Laravel AI agents normally.

## Error Handling

Observability must not break agent execution.

Rules:

- Disabled package is a no-op.
- Missing optional event classes must not crash boot.
- Exporter exceptions must not bubble into application code.
- Stack exporter should attempt all configured channels.
- Database write failures should be logged and swallowed.
- OTLP failures should be logged and swallowed.
- Invalid exporter config should fail closed to `null` or log a clear warning.
- Export should happen after response or through queue/defer modes when configured.

## Testing Strategy

Use package-local Pest tests with Orchestra Testbench.

Required test groups:

- Service provider registers config and package bindings.
- Config can be published.
- Event subscriber maps Laravel AI lifecycle events to OpenInference traces.
- Tool events create child spans.
- Full content capture includes input, output, tool arguments, and tool results when present.
- Log exporter writes structured payloads.
- Null exporter drops payloads.
- Stack exporter calls all configured exporters.
- Stack exporter continues when one exporter fails.
- Database exporter stores trace and span rows.
- OTLP exporter produces expected request payloads with fake HTTP client.
- Phoenix, Langfuse, and Braintrust presets resolve expected endpoints and headers.
- Package does not crash when optional Laravel AI event classes are unavailable.
- Exporter failures do not bubble into agent execution.

## Source References

- Laravel package development: `https://laravel.com/docs/12.x/packages`
- Laravel AI SDK events: `https://laravel.com/docs/12.x/ai-sdk#events`
- Laravel queue deferred/background dispatching: `https://laravel.com/docs/12.x/queues#deferred-dispatching`
- Spatie Laravel Package Tools: `https://github.com/spatie/laravel-package-tools`
- OpenInference semantic conventions: `https://github.com/Arize-ai/openinference/blob/main/spec/semantic_conventions.md`
- Phoenix tracing: `https://arize.com/docs/phoenix/tracing/concepts-tracing/how-tracing-works`
- Langfuse OpenTelemetry integration: `https://langfuse.com/integrations/native/opentelemetry`
- Braintrust OpenTelemetry integration: `https://www.braintrust.dev/docs/integrations/sdk-integrations/opentelemetry`
- Laravel AI issue 618: `https://github.com/laravel/ai/issues/618`
- Laravel AI PR 137: `https://github.com/laravel/ai/pull/137`
- Laravel AI PR 349: `https://github.com/laravel/ai/pull/349`
