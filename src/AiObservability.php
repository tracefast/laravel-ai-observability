<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Throwable;
use Tracefast\LaravelAiObservability\Context\ObservationContext;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;
use Tracefast\LaravelAiObservability\Jobs\ExportTraceJob;

use function Illuminate\Support\defer;

class AiObservability
{
    public function __construct(
        private readonly ExporterManager $exporters,
        private readonly ObservationContext $context,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ai-observability.enabled', false);
    }

    public function exporter(?string $name = null): Exporter
    {
        return $this->exporters->exporter($name);
    }

    public function export(Trace $trace): void
    {
        if (! $this->sampled($trace)) {
            return;
        }

        $export = function () use ($trace): void {
            try {
                $this->exporter()->export($trace);
            } catch (Throwable $exception) {
                report($exception);
            }
        };

        if ($this->exportMode() === 'sync') {
            $export();

            return;
        }

        if (in_array($this->exportMode(), ['queue', 'background'], true)) {
            try {
                app(BusDispatcher::class)->dispatch(new ExportTraceJob(
                    trace: $trace->toArray(),
                    connection: $this->queueConnection(),
                    queue: $this->queueName(),
                ));
            } catch (Throwable $exception) {
                report($exception);
            }

            return;
        }

        defer($export, "ai-observability:{$trace->traceId()}")->always();
    }

    /**
     * @param  Closure(mixed ...): Exporter  $creator
     */
    public function extend(string $driver, Closure $creator): void
    {
        $this->exporters->extend($driver, $creator);
    }

    /**
     * @template TReturn
     *
     * @param  array<string, mixed>  $attributes
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withAttributes(array $attributes, Closure $callback): mixed
    {
        return $this->context->withAttributes($attributes, $callback);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @param  array<string, mixed>  $attributes
     * @return TReturn
     */
    public function withSession(string $sessionId, Closure $callback, string|int|null $userId = null, array $attributes = []): mixed
    {
        $scoped = array_merge($attributes, [
            'session.id' => $sessionId,
            'user.id' => $userId,
        ]);

        return $this->withAttributes($scoped, $callback);
    }

    private function exportMode(): string
    {
        $mode = strtolower(trim((string) config('ai-observability.export.mode', 'defer')));

        if (in_array($mode, ['defer', 'sync', 'queue', 'background'], true)) {
            return $mode;
        }

        return 'defer';
    }

    private function sampled(Trace $trace): bool
    {
        $rate = $this->sampleRate();

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        $traceId = $trace->traceId();

        if (ctype_xdigit($traceId) && strlen($traceId) >= 8) {
            $value = hexdec(substr($traceId, 0, 8)) / 0xFFFFFFFF;
        } else {
            $value = (int) sprintf('%u', crc32($traceId)) / 0xFFFFFFFF;
        }

        return $value <= $rate;
    }

    private function sampleRate(): float
    {
        $rate = config('ai-observability.export.sample_rate', 1.0);

        if (! is_numeric($rate)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $rate));
    }

    private function queueConnection(): ?string
    {
        $connection = config('ai-observability.export.connection');

        if (is_string($connection) && trim($connection) !== '') {
            return trim($connection);
        }

        return $this->exportMode() === 'background' ? 'background' : null;
    }

    private function queueName(): ?string
    {
        $queue = config('ai-observability.export.queue');

        return is_string($queue) && trim($queue) !== '' ? trim($queue) : null;
    }
}
