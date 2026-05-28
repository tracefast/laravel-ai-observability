<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

use Random\RandomException;

final class Ids
{
    /**
     * @throws RandomException
     */
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @throws RandomException
     */
    public static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
