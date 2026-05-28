<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class Clock
{
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function durationMs(?string $start, ?string $end): ?float
    {
        if ($start === null || $end === null) {
            return null;
        }

        try {
            $startedAt = new DateTimeImmutable($start);
            $endedAt = new DateTimeImmutable($end);
        } catch (Throwable) {
            return null;
        }

        $startedAtSeconds = (float) $startedAt->format('U.u');
        $endedAtSeconds = (float) $endedAt->format('U.u');

        return round(($endedAtSeconds - $startedAtSeconds) * 1000, 3);
    }
}
