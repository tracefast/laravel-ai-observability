<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool enabled()
 */
class AiObservability extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'ai-observability';
    }
}
