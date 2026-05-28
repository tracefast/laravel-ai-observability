<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

final class Arr
{
    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $items): array
    {
        return array_filter($items, fn (mixed $value): bool => $value !== null);
    }
}
