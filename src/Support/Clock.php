<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function durationMs(?string $start, ?string $end): ?float
    {
        $startedAt = self::parse($start);
        $endedAt = self::parse($end);

        if ($startedAt === null || $endedAt === null) {
            return null;
        }

        $durationMs = round(((float) $endedAt->format('U.u') - (float) $startedAt->format('U.u')) * 1000, 3);

        if ($durationMs < 0) {
            return null;
        }

        return $durationMs;
    }

    private static function parse(?string $timestamp): ?DateTimeImmutable
    {
        if ($timestamp === null) {
            return null;
        }

        $dateTime = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.u\Z',
            $timestamp,
            new DateTimeZone('UTC'),
        );

        $errors = DateTimeImmutable::getLastErrors();

        if ($dateTime === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $dateTime;
    }
}
