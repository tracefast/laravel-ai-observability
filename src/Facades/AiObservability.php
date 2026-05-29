<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 * @method static mixed withAttributes(array $attributes, \Closure $callback)
 * @method static mixed withSession(string $sessionId, \Closure $callback, string|int|null $userId = null, array $attributes = [])
 */
class AiObservability extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai-observability';
    }
}
