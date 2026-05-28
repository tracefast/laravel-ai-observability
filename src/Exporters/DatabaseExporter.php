<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
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
            $connection = DB::connection($this->connection);
            $now = now();

            $connection->transaction(function () use ($connection, $now, $trace): void {
                $payload = $trace->toArray();

                $connection
                    ->table('ai_observability_traces')
                    ->insert([
                        'trace_id' => $payload['trace_id'],
                        'name' => $payload['name'],
                        'status' => $payload['status'],
                        'started_at' => $this->timestamp($payload['started_at'] ?? null),
                        'ended_at' => $this->timestamp($payload['ended_at'] ?? null),
                        'duration_ms' => $payload['duration_ms'] ?? null,
                        'exported_at' => $now,
                        'payload' => $this->json($payload),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                foreach ($trace->spans() as $span) {
                    $this->insertSpan($connection, $span, $now);
                }
            });
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * @throws JsonException
     */
    private function insertSpan(ConnectionInterface $connection, Span $span, DateTimeInterface $now): void
    {
        $payload = $span->toArray();

        $connection
            ->table('ai_observability_spans')
            ->insert([
                'trace_id' => $payload['trace_id'],
                'span_id' => $payload['span_id'],
                'parent_span_id' => $payload['parent_span_id'],
                'name' => $payload['name'],
                'kind' => $payload['kind'],
                'status' => $payload['status'],
                'started_at' => $this->timestamp($payload['started_at'] ?? null),
                'ended_at' => $this->timestamp($payload['ended_at'] ?? null),
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

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $value,
            new DateTimeZone('UTC'),
        );

        $errors = DateTimeImmutable::getLastErrors();

        if ($dateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $dateTime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
