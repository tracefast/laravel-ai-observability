<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\SpanStatus;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\DatabaseExporter;

beforeEach(function (): void {
    Schema::create('ai_observability_traces', function (Blueprint $table): void {
        $table->id();
        $table->string('trace_id')->index();
        $table->string('name');
        $table->string('status');
        $table->timestampTz('started_at', 6)->nullable();
        $table->timestampTz('ended_at', 6)->nullable();
        $table->float('duration_ms')->nullable();
        $table->timestampTz('exported_at', 6)->nullable();
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
        $table->timestampTz('started_at', 6)->nullable();
        $table->timestampTz('ended_at', 6)->nullable();
        $table->float('duration_ms')->nullable();
        $table->json('attributes')->nullable();
        $table->json('input')->nullable();
        $table->json('output')->nullable();
        $table->json('payload');
        $table->timestamps();
    });
});

it('stores trace and span rows', function (): void {
    $trace = (new Trace(
        traceId: 'trace_database_123',
        name: 'database export',
        startedAt: '2026-01-01T00:00:00.000000Z',
    ))->finish(
        endedAt: '2026-01-01T00:00:01.250000Z',
        status: SpanStatus::Ok,
    );

    $trace->addSpan((new Span(
        traceId: 'trace_database_123',
        spanId: 'span_database_123',
        parentSpanId: null,
        name: 'chat completion',
        kind: SpanKind::Llm,
        status: SpanStatus::Ok,
        startedAt: '2026-01-01T00:00:00.100000Z',
        attributes: [
            'llm.model_name' => 'gpt-4.1',
        ],
        input: null,
        output: ['content' => 'Hello'],
    ))->finish(
        endedAt: '2026-01-01T00:00:01.000000Z',
        status: SpanStatus::Ok,
    ));

    (new DatabaseExporter(connection: null))->export($trace);

    $traceRow = DB::table('ai_observability_traces')->first();
    $spanRow = DB::table('ai_observability_spans')->first();

    expect(DB::table('ai_observability_traces')->count())->toBe(1)
        ->and(DB::table('ai_observability_spans')->count())->toBe(1)
        ->and($traceRow->trace_id)->toBe('trace_database_123')
        ->and($traceRow->started_at)->toBe('2026-01-01 00:00:00.000000')
        ->and($traceRow->ended_at)->toBe('2026-01-01 00:00:01.250000')
        ->and(json_decode($traceRow->payload, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'trace_id' => 'trace_database_123',
            'duration_ms' => 1250.0,
        ])
        ->and($spanRow->trace_id)->toBe('trace_database_123')
        ->and($spanRow->span_id)->toBe('span_database_123')
        ->and($spanRow->started_at)->toBe('2026-01-01 00:00:00.100000')
        ->and($spanRow->ended_at)->toBe('2026-01-01 00:00:01.000000')
        ->and(json_decode($spanRow->attributes, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'llm.model_name' => 'gpt-4.1',
            'llm.system' => 'unknown',
            'openinference.span.kind' => 'LLM',
            'openinference.schema.version' => '1.0.0',
            'tracefast.ai.package.name' => 'tracefast/laravel-ai-observability',
            'tracefast.ai.package.version' => 'dev-main',
            'tracefast.ai.sdk.name' => 'laravel/ai',
            'tracefast.ai.sdk.version' => 'v0.7.2',
        ])
        ->and(json_decode($spanRow->input, true, flags: JSON_THROW_ON_ERROR))->toBeNull()
        ->and(json_decode($spanRow->output, true, flags: JSON_THROW_ON_ERROR))->toBe(['content' => 'Hello'])
        ->and(json_decode($spanRow->payload, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'trace_id' => 'trace_database_123',
            'span_id' => 'span_database_123',
            'attributes' => [
                'llm.model_name' => 'gpt-4.1',
                'llm.system' => 'unknown',
                'openinference.span.kind' => 'LLM',
                'openinference.schema.version' => '1.0.0',
                'tracefast.ai.package.name' => 'tracefast/laravel-ai-observability',
                'tracefast.ai.package.version' => 'dev-main',
                'tracefast.ai.sdk.name' => 'laravel/ai',
                'tracefast.ai.sdk.version' => 'v0.7.2',
            ],
        ]);
});

it('rolls back trace rows when span inserts fail', function (): void {
    $trace = new Trace(
        traceId: 'trace_rollback_123',
        name: 'rollback export',
        startedAt: '2026-01-01T00:00:00.000000Z',
    );

    $trace->addSpan(new Span(
        traceId: 'trace_rollback_123',
        spanId: 'span_rollback_123',
        parentSpanId: null,
        name: 'missing span table',
        kind: SpanKind::Llm,
    ));

    Schema::drop('ai_observability_spans');

    (new DatabaseExporter(connection: null))->export($trace);

    expect(DB::table('ai_observability_traces')->count())->toBe(0);
});
