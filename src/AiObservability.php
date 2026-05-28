<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

class AiObservability
{
    public function enabled(): bool
    {
        return (bool) config('ai-observability.enabled', false);
    }
}
