<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exceptions;

use RuntimeException;

final class PayloadTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $payloadBytes,
        public readonly int $maxPayloadBytes,
    ) {
        parent::__construct("AI observability payload is {$payloadBytes} bytes, exceeding the {$maxPayloadBytes} byte limit.");
    }
}
