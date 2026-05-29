<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Context;

use BackedEnum;
use Closure;
use Stringable;

final class ObservationContext
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
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
        $previous = $this->attributes;
        $this->attributes = array_merge($this->attributes, $this->normalize($attributes));

        try {
            return $callback();
        } finally {
            $this->attributes = $previous;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $value = $this->attributeValue($value);

            if ($value === null) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function attributeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return is_bool($value) ? $value : (string) $value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return $value;
    }
}
