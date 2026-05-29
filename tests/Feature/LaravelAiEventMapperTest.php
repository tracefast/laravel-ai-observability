<?php

declare(strict_types=1);

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

it('maps prompting events into root agent span fields', function (): void {
    $payload = (new LaravelAiEventMapper)->prompting(new FakePromptingEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-123',
        'name' => 'Screening Agent',
        'input' => 'Summarize this candidate.',
        'attributes' => [
            'openinference.span.kind' => 'agent',
            'tracefast.ai.invocation_id' => 'invocation-123',
            'llm.provider' => 'openai',
            'llm.model_name' => 'gpt-4.1-mini',
        ],
    ]);
});

it('maps prompted events with response output and usage fields', function (): void {
    $payload = (new LaravelAiEventMapper)->prompted(new FakePromptedEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-123',
        'output' => 'The candidate is a strong fit.',
        'attributes' => [
            'llm.provider' => 'openai',
            'llm.model_name' => 'gpt-4.1-mini',
            'llm.token_count.prompt' => 17,
            'llm.token_count.completion' => 8,
            'tracefast.ai.conversation_id' => 'conversation-456',
            'tracefast.ai.response_type' => 'text',
        ],
    ]);
});

it('falls back to the response class basename when response type is not explicit', function (): void {
    $payload = (new LaravelAiEventMapper)->prompted(new FakePromptedEventWithoutResponseType);

    expect($payload['attributes']['tracefast.ai.response_type'])->toBe('FakeResponseWithoutType');
});

it('maps tool invocation arguments and results', function (): void {
    $mapper = new LaravelAiEventMapper;

    expect($mapper->invokingTool(new FakeInvokingToolEvent))->toMatchArray([
        'invocation_id' => 'invocation-123',
        'tool_invocation_id' => 'tool-call-789',
        'name' => 'lookup_candidate',
        'input' => ['candidate_id' => 42],
        'attributes' => [
            'openinference.span.kind' => 'tool',
            'tool.name' => 'lookup_candidate',
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
            'openinference.span.kind' => 'agent',
        ],
    ])->and($mapper->prompted($event))->toMatchArray([
        'invocation_id' => null,
        'output' => null,
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
