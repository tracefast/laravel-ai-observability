<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\Context\ObservationContext;
use Tracefast\LaravelAiObservability\Data\Span;
use Tracefast\LaravelAiObservability\Data\SpanKind;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\LaravelAi\EventClassMap;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventMapper;
use Tracefast\LaravelAiObservability\Registry\InMemoryTraceRegistry;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeInvokingToolEvent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeNestedToolOutput;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptedEvent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptedEventWithoutResponseType;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptingEvent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeToolInvokedEvent;

require_once __DIR__.'/../Fixtures/FakeEvents.php';

it('stores and forgets traces by invocation id', function (): void {
    $registry = new InMemoryTraceRegistry;
    $trace = new Trace(traceId: 'trace-1', name: 'Agent run');
    $rootSpan = new Span(
        traceId: 'trace-1',
        spanId: 'span-1',
        parentSpanId: null,
        name: 'Screening Agent',
        kind: SpanKind::Agent,
    );

    $registry->start('invocation-123', $trace, $rootSpan);

    expect($registry->trace('invocation-123'))->toBe($trace)
        ->and($registry->rootSpan('invocation-123'))->toBe($rootSpan)
        ->and($trace->spans())->toBe([$rootSpan]);

    $registry->forget('invocation-123');

    expect($registry->trace('invocation-123'))->toBeNull()
        ->and($registry->rootSpan('invocation-123'))->toBeNull();
});

it('maps prompting events into agent and llm span fields', function (): void {
    $payload = (new LaravelAiEventMapper)->prompting(new FakePromptingEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-123',
        'name' => 'Screening Agent',
        'input' => 'Summarize this candidate.',
        'attributes' => [
            'openinference.span.kind' => 'AGENT',
            'tracefast.ai.invocation_id' => 'invocation-123',
        ],
        'llm_span' => [
            'name' => 'chat gpt-4.1-mini',
            'input' => 'Summarize this candidate.',
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'openai',
                'tracefast.ai.invocation_id' => 'invocation-123',
                'llm.provider' => 'openai',
                'llm.model_name' => 'gpt-4.1-mini',
                'gen_ai.operation.name' => 'chat',
                'gen_ai.system' => 'openai',
                'gen_ai.provider.name' => 'openai',
                'gen_ai.request.model' => 'gpt-4.1-mini',
                'gen_ai.prompt' => 'Summarize this candidate.',
                'gen_ai.prompt_json' => json_encode([
                    ['role' => 'user', 'content' => 'Summarize this candidate.'],
                ], JSON_THROW_ON_ERROR),
                'llm.input_messages.0.message.role' => 'user',
                'llm.input_messages.0.message.content' => 'Summarize this candidate.',
            ],
        ],
    ]);
});

it('maps prompted events with root response metadata and llm usage fields', function (): void {
    $payload = (new LaravelAiEventMapper)->prompted(new FakePromptedEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-123',
        'output' => 'The candidate is a strong fit.',
        'attributes' => [
            'tracefast.ai.conversation_id' => 'conversation-456',
            'tracefast.ai.response_type' => 'text',
        ],
        'llm_span' => [
            'output' => 'The candidate is a strong fit.',
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'openai',
                'llm.provider' => 'openai',
                'llm.model_name' => 'gpt-4.1-mini',
                'llm.token_count.prompt' => 17,
                'llm.token_count.completion' => 8,
                'llm.token_count.total' => 25,
                'gen_ai.system' => 'openai',
                'gen_ai.provider.name' => 'openai',
                'gen_ai.response.model' => 'gpt-4.1-mini',
                'gen_ai.completion' => 'The candidate is a strong fit.',
                'gen_ai.usage.input_tokens' => 17,
                'gen_ai.usage.output_tokens' => 8,
                'tracefast.ai.conversation_id' => 'conversation-456',
                'tracefast.ai.response_type' => 'text',
                'llm.output_messages.0.message.role' => 'assistant',
                'llm.output_messages.0.message.content' => 'The candidate is a strong fit.',
            ],
        ],
    ]);
});

it('maps OpenInference LLM messages and tool call attributes with flattened keys', function (): void {
    $mapper = new LaravelAiEventMapper;
    $promptingPayload = $mapper->prompting(new FakePromptingEvent);
    $promptedPayload = $mapper->prompted(new FakePromptedEvent);
    $toolPayload = $mapper->invokingTool(new FakeInvokingToolEvent);

    expect($promptingPayload['llm_span']['attributes'])->toMatchArray([
        'openinference.span.kind' => 'LLM',
        'llm.system' => 'openai',
        'llm.input_messages.0.message.role' => 'user',
        'llm.input_messages.0.message.content' => 'Summarize this candidate.',
    ])->and($promptedPayload['llm_span']['attributes'])->toMatchArray([
        'openinference.span.kind' => 'LLM',
        'llm.output_messages.0.message.role' => 'assistant',
        'llm.output_messages.0.message.content' => 'The candidate is a strong fit.',
        'llm.token_count.total' => 25,
    ])->and($toolPayload['attributes'])->toMatchArray([
        'openinference.span.kind' => 'TOOL',
        'tool.id' => 'tool-call-789',
        'tool.name' => 'lookup_candidate',
    ]);
});

it('does not attach llm usage fields to root agent mapper attributes', function (): void {
    $promptingPayload = (new LaravelAiEventMapper)->prompting(new FakePromptingEvent);
    $promptedPayload = (new LaravelAiEventMapper)->prompted(new FakePromptedEvent);

    expect($promptingPayload['attributes'])->not->toHaveKeys([
        'llm.provider',
        'llm.model_name',
        'gen_ai.operation.name',
        'gen_ai.request.model',
    ])->and($promptedPayload['attributes'])->not->toHaveKeys([
        'llm.token_count.prompt',
        'llm.token_count.completion',
        'gen_ai.usage.input_tokens',
        'gen_ai.usage.output_tokens',
    ]);
});

it('adds scoped observation attributes to mapped agent llm and tool spans', function (): void {
    $context = new ObservationContext;

    $context->withAttributes([
        'session.id' => 'conversation-123',
        'user.id' => 42,
    ], function () use ($context): void {
        $mapper = new LaravelAiEventMapper($context);
        $promptingPayload = $mapper->prompting(new FakePromptingEvent);
        $promptedPayload = $mapper->prompted(new FakePromptedEvent);

        expect($promptingPayload['attributes'])->toMatchArray([
            'session.id' => 'conversation-123',
            'user.id' => '42',
        ])->and($promptingPayload['llm_span']['attributes'])->toMatchArray([
            'session.id' => 'conversation-123',
            'user.id' => '42',
        ])->and($promptedPayload['attributes'])->toMatchArray([
            'session.id' => 'conversation-123',
            'user.id' => '42',
        ])->and($promptedPayload['llm_span']['attributes'])->toMatchArray([
            'session.id' => 'conversation-123',
            'user.id' => '42',
        ])->and($mapper->invokingTool(new FakeInvokingToolEvent)['attributes'])->toMatchArray([
            'session.id' => 'conversation-123',
            'user.id' => '42',
        ]);
    });
});

it('falls back to the response class basename when response type is not explicit', function (): void {
    $payload = (new LaravelAiEventMapper)->prompted(new FakePromptedEventWithoutResponseType);

    expect($payload['attributes']['tracefast.ai.response_type'])->toBe('FakeResponseWithoutType')
        ->and($payload['llm_span']['attributes']['tracefast.ai.response_type'])->toBe('FakeResponseWithoutType');
});

it('maps tool invocation arguments and results', function (): void {
    $mapper = new LaravelAiEventMapper;

    expect($mapper->invokingTool(new FakeInvokingToolEvent))->toMatchArray([
        'invocation_id' => 'invocation-123',
        'tool_invocation_id' => 'tool-call-789',
        'name' => 'lookup_candidate',
        'input' => ['candidate_id' => 42],
        'attributes' => [
            'openinference.span.kind' => 'TOOL',
            'tool.name' => 'lookup_candidate',
            'tool.id' => 'tool-call-789',
            'tool.call.id' => 'tool-call-789',
        ],
    ])->and($mapper->toolInvoked(new FakeToolInvokedEvent))->toMatchArray([
        'invocation_id' => 'invocation-123',
        'tool_invocation_id' => 'tool-call-789',
        'output' => ['name' => 'Ada Lovelace'],
    ]);
});

it('sanitizes nested mixed tool output for json encoding', function (): void {
    $resource = fopen('php://memory', 'r');

    $payload = (new LaravelAiEventMapper)->toolInvoked(new FakeToolInvokedEvent(result: [
        'plain' => 'value',
        'nested' => [
            'object' => new FakeNestedToolOutput('Ada Lovelace', (object) ['role' => 'engineer']),
            'closure' => fn (): bool => true,
            'resource' => $resource,
        ],
    ]));

    expect(json_encode($payload, JSON_THROW_ON_ERROR))->toBeString()
        ->and($payload['output']['nested']['object'])->toMatchArray([
            'name' => 'Ada Lovelace',
            'nested' => ['role' => 'engineer'],
        ])
        ->and($payload['output']['nested']['closure'])->toBe('[closure]')
        ->and($payload['output']['nested']['resource'])->toStartWith('[resource:');
});

it('maps null-like events without crashing', function (): void {
    $mapper = new LaravelAiEventMapper;
    $event = new stdClass;

    expect($mapper->prompting($event))->toMatchArray([
        'invocation_id' => null,
        'name' => 'stdClass',
        'input' => null,
        'attributes' => [
            'openinference.span.kind' => 'AGENT',
        ],
        'llm_span' => [
            'name' => 'chat',
            'input' => null,
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'unknown',
            ],
        ],
    ])->and($mapper->prompted($event))->toMatchArray([
        'invocation_id' => null,
        'output' => null,
        'attributes' => [],
        'llm_span' => [
            'output' => null,
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'unknown',
            ],
        ],
    ])->and($mapper->invokingTool($event))->toMatchArray([
        'invocation_id' => null,
        'tool_invocation_id' => null,
        'name' => 'tool',
        'input' => null,
    ])->and($mapper->toolInvoked($event))->toMatchArray([
        'invocation_id' => null,
        'tool_invocation_id' => null,
        'output' => null,
    ]);
});

it('only returns event classes that exist', function (): void {
    foreach (EventClassMap::events() as $event) {
        expect($event)->toBeString()
            ->and(class_exists($event))->toBeTrue();
    }
});
