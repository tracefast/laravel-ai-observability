<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\Trace;

final class DatabaseExporter implements Exporter
{
    public function __construct(
        private readonly ?string $connection,
    ) {}

    public function export(Trace $trace): void
    {
        try {
            $payload = $trace->toArray();
            $now = now();

            DB::connection($this->connection)
                ->table('ai_observability_traces')
                ->insert([
                    'trace_id' => $payload['trace_id'],
                    'name' => $payload['name'],
                    'status' => $payload['status'],
                    'started_at' => $payload['started_at'] ?? null,
                    'ended_at' => $payload['ended_at'] ?? null,
                    'duration_ms' => $payload['duration_ms'] ?? null,
                    'exported_at' => $now,
                    'payload' => $this->json($payload),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach ($trace->spans() as $span) {
                $this->insertSpan($span, $now);
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * @throws JsonException
     */
    private function insertSpan(Span $span, DateTimeInterface $now): void
    {
        $payload = $span->toArray();

        DB::connection($this->connection)
            ->table('ai_observability_spans')
            ->insert([
                'trace_id' => $payload['trace_id'],
                'span_id' => $payload['span_id'],
                'parent_span_id' => $payload['parent_span_id'],
                'name' => $payload['name'],
                'kind' => $payload['kind'],
                'status' => $payload['status'],
                'started_at' => $payload['started_at'] ?? null,
                'ended_at' => $payload['ended_at'] ?? null,
                'duration_ms' => $payload['duration_ms'] ?? null,
                'attributes' => $this->json($payload['attributes']),
                'input' => $this->json($payload['input']),
                'output' => $this->json($payload['output']),
                'payload' => $this->json($payload),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * @throws JsonException
     */
    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
