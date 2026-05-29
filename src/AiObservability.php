<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Closure;
use Throwable;
use Tracefast\LaravelAiObservability\Context\ObservationContext;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;

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

        defer($export)->always();
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

        if (in_array($mode, ['defer', 'sync'], true)) {
            return $mode;
        }

        return 'defer';
    }
}
