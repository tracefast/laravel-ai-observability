# Simplified Configuration Design

Date: 2026-05-29

Package: `tracefast/laravel-ai-observability`

## Purpose

Make the package feel simple at install time. A developer who installs the package should see traces without wiring several environment variables, and should be able to send traces to one or many receivers with one clear setting.

The package should keep OTLP/OpenInference behavior generic. Phoenix, Langfuse, and Braintrust are receiver examples, not separate tracing models.

## Goals

- Enable observability by default after the package is installed.
- Use `log` as the default exporter so installs do not make network calls.
- Allow comma-separated exporters, for example `AI_OBSERVABILITY_EXPORTER=phoenix,langfuse`.
- Keep named provider presets for good developer experience.
- Keep provider-specific details limited to endpoint and header configuration.
- Rewrite the README to be short, direct, and professional.

## Non-Goals

- Do not add vendor-specific span attributes for Braintrust, Langfuse, or Phoenix.
- Do not replace OTLP/OpenInference with a custom TraceFast transport.
- Do not add a setup wizard, Artisan installer, dashboard UI, or redaction system.
- Do not change the trace/span data model.

## Configuration Contract

The package config should default to enabled:

```php
'enabled' => env('AI_OBSERVABILITY_ENABLED', true),
```

The default exporter should be `log`:

```php
'default' => env('AI_OBSERVABILITY_EXPORTER', 'log'),
```

`AI_OBSERVABILITY_EXPORTER` should accept:

- A single exporter name: `log`, `phoenix`, `langfuse`, `braintrust`, `database`, `null`
- A comma-separated list: `phoenix,langfuse`
- Whitespace around comma-separated names should be ignored

When more than one exporter is configured, the package should treat the list as an implicit stack. The existing `stack` exporter can remain available for advanced config arrays, but users should not need to publish config just to send traces to multiple receivers.

## Exporter Behavior

Single exporter:

```env
AI_OBSERVABILITY_EXPORTER=log
```

Multiple exporters:

```env
AI_OBSERVABILITY_EXPORTER=phoenix,langfuse
```

The exporter manager should resolve that list and fan out to both exporters. It should keep the current stack behavior: try every exporter and do not let one exporter failure block the others.

If the list contains an unknown exporter, the package should keep the current controlled configuration exception behavior.

## Provider Examples

The README should present the smallest useful snippets.

Default local logging:

```env
# Optional. This is the default after install.
AI_OBSERVABILITY_EXPORTER=log
```

Phoenix:

```env
AI_OBSERVABILITY_EXPORTER=phoenix
PHOENIX_COLLECTOR_ENDPOINT=http://localhost:6006/v1/traces
```

Langfuse:

```env
AI_OBSERVABILITY_EXPORTER=langfuse
LANGFUSE_OTEL_ENDPOINT=https://cloud.langfuse.com/api/public/otel/v1/traces
LANGFUSE_OTEL_AUTHORIZATION="Basic <base64-public-key-colon-secret-key>"
```

Braintrust:

```env
AI_OBSERVABILITY_EXPORTER=braintrust
BRAINTRUST_API_KEY=<braintrust-api-key>
BRAINTRUST_PARENT=project_name:nexxa
```

Multiple receivers:

```env
AI_OBSERVABILITY_EXPORTER=phoenix,langfuse
```

`BRAINTRUST_OTEL_ENDPOINT`, `LANGFUSE_INGESTION_VERSION`, `AI_OBSERVABILITY_LOG_CHANNEL`, `AI_OBSERVABILITY_LOG_LEVEL`, `AI_OBSERVABILITY_EXPORT_MODE`, `AI_OBSERVABILITY_EXPORT_TIMEOUT`, and `AI_OBSERVABILITY_CAPTURE_CONTENT` should stay supported, but they belong in an advanced configuration section rather than the quick start.

## README Shape

The README should read like a package README, not a generated spec.

Recommended structure:

1. One-line purpose
2. Requirements
3. Installation
4. Quick start
5. Exporting to Phoenix, Langfuse, Braintrust, or multiple receivers
6. Full content capture note
7. Conversation/session correlation
8. Database exporter
9. Custom exporters
10. Testing and license

The quick start should be enough for a local Laravel app:

```bash
composer require tracefast/laravel-ai-observability
```

Then:

```env
# Optional. Defaults to log.
AI_OBSERVABILITY_EXPORTER=log
```

The content capture warning should remain clear, but short. V1 captures full input/output by default.

## Testing

Add or update tests for:

- Config defaults: enabled is true and default exporter is `log`.
- Single exporter resolution still works.
- Comma-separated exporter resolution fans out to all listed exporters.
- Comma-separated exporter names are trimmed.
- README contains the new concise examples.

Existing package tests should continue to pass.

## Open Questions

- None. The approved direction is comma-separated exporters with `log` as the safe default.
