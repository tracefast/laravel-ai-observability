<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

enum SpanStatus: string
{
    case Unset = 'unset';
    case Ok = 'ok';
    case Error = 'error';
}
