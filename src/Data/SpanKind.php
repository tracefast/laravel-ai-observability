<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Data;

enum SpanKind: string
{
    case Agent = 'agent';
    case Chain = 'chain';
    case Llm = 'llm';
    case Tool = 'tool';
    case Embedding = 'embedding';
    case Retriever = 'retriever';
    case Reranker = 'reranker';
}
