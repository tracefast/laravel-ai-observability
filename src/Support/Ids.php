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
        return self::hex(16);
    }

    /**
     * @throws RandomException
     */
    public static function spanId(): string
    {
        return self::hex(8);
    }

    /**
     * @throws RandomException
     */
    private static function hex(int $bytes): string
    {
        do {
            $id = bin2hex(random_bytes($bytes));
        } while (preg_match('/^0+$/', $id) === 1);

        return $id;
    }
}
