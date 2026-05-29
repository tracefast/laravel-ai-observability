<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Contracts\TraceRegistry;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\LaravelAi\EventClassMap;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventSubscriber;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeInvokingToolEvent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptedEvent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakePromptingEvent;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeToolInvokedEvent;

require_once __DIR__.'/../Fixtures/FakeEvents.php';

final class CapturingExporter implements Exporter
{
    /**
     * @var list<Trace>
     */
    public array $traces = [];

    public function export(Trace $trace): void
    {
        $this->traces[] = $trace;
    }
}

final class FailingExporter implements Exporter
{
    public function export(Trace $trace): void
    {
        throw new RuntimeException('Exporter failed.');
    }
}

it('subscribes laravel ai lifecycle events to their handlers', function (): void {
    $dispatcher = Mockery::mock(Dispatcher::class);

    $dispatcher
        ->shouldReceive('listen')
        ->with('Laravel\\Ai\\Events\\PromptingAgent', [LaravelAiEventSubscriber::class, 'handlePrompting'])
        ->once();
    $dispatcher
        ->shouldReceive('listen')
        ->with('Laravel\\Ai\\Events\\StreamingAgent', [LaravelAiEventSubscriber::class, 'handlePrompting'])
        ->once();
    $dispatcher
        ->shouldReceive('listen')
        ->with('Laravel\\Ai\\Events\\AgentPrompted', [LaravelAiEventSubscriber::class, 'handlePrompted'])
        ->once();
    $dispatcher
        ->shouldReceive('listen')
        ->with('Laravel\\Ai\\Events\\AgentStreamed', [LaravelAiEventSubscriber::class, 'handlePrompted'])
        ->once();
    $dispatcher
        ->shouldReceive('listen')
        ->with('Laravel\\Ai\\Events\\InvokingTool', [LaravelAiEventSubscriber::class, 'handleInvokingTool'])
        ->once();
    $dispatcher
        ->shouldReceive('listen')
        ->with('Laravel\\Ai\\Events\\ToolInvoked', [LaravelAiEventSubscriber::class, 'handleToolInvoked'])
        ->once();

    app(LaravelAiEventSubscriber::class)->subscribe($dispatcher);
});

it('exports an agent lifecycle trace and forgets registry state', function (): void {
    config()->set('ai-observability.enabled', true);
    config()->set('ai-observability.default', 'capturing');
    config()->set('ai-observability.exporters.capturing', ['driver' => 'capturing']);

    $exporter = new CapturingExporter;
    app('ai-observability')->extend('capturing', fn (): CapturingExporter => $exporter);

    $subscriber = app(LaravelAiEventSubscriber::class);
    $registry = app(TraceRegistry::class);

    $subscriber->handlePrompting(new FakePromptingEvent);

    expect($registry->trace('invocation-123'))->not->toBeNull()
        ->and($registry->rootSpan('invocation-123'))->not->toBeNull();

    $subscriber->handleInvokingTool(new FakeInvokingToolEvent);
    $subscriber->handleToolInvoked(new FakeToolInvokedEvent);
    $subscriber->handlePrompted(new FakePromptedEvent);

    expect($registry->trace('invocation-123'))->toBeNull()
        ->and($registry->rootSpan('invocation-123'))->toBeNull()
        ->and($exporter->traces)->toHaveCount(1);

    $trace = $exporter->traces[0]->toArray();

    expect($trace)->toMatchArray([
        'name' => 'Screening Agent',
        'status' => 'ok',
    ])
        ->and($trace['spans'])->toHaveCount(2)
        ->and($trace['spans'][0])->toMatchArray([
            'name' => 'Screening Agent',
            'kind' => 'agent',
            'status' => 'ok',
            'input' => 'Summarize this candidate.',
            'output' => 'The candidate is a strong fit.',
        ])
        ->and($trace['spans'][0]['attributes'])->toMatchArray([
            'tracefast.ai.invocation_id' => 'invocation-123',
            'tracefast.ai.conversation_id' => 'conversation-456',
            'llm.token_count.prompt' => 17,
            'llm.token_count.completion' => 8,
        ])
        ->and($trace['spans'][1])->toMatchArray([
            'name' => 'lookup_candidate',
            'kind' => 'tool',
            'parent_span_id' => $trace['spans'][0]['span_id'],
            'status' => 'ok',
            'input' => ['candidate_id' => 42],
            'output' => ['name' => 'Ada Lovelace'],
        ])
        ->and($trace['spans'][1]['attributes'])->toMatchArray([
            'tool.call.id' => 'tool-call-789',
        ]);
});

it('forgets registry state when the exporter throws', function (): void {
    config()->set('ai-observability.enabled', true);
    config()->set('ai-observability.default', 'failing');
    config()->set('ai-observability.exporters.failing', ['driver' => 'failing']);

    app('ai-observability')->extend('failing', fn (): FailingExporter => new FailingExporter);

    $subscriber = app(LaravelAiEventSubscriber::class);
    $registry = app(TraceRegistry::class);

    $subscriber->handlePrompting(new FakePromptingEvent);

    expect($registry->trace('invocation-123'))->not->toBeNull();

    $subscriber->handlePrompted(new FakePromptedEvent);

    expect($registry->trace('invocation-123'))->toBeNull()
        ->and($registry->rootSpan('invocation-123'))->toBeNull();
});

it('does nothing while disabled or without invocation ids', function (): void {
    config()->set('ai-observability.enabled', false);
    config()->set('ai-observability.default', 'capturing');
    config()->set('ai-observability.exporters.capturing', ['driver' => 'capturing']);

    $exporter = new CapturingExporter;
    app('ai-observability')->extend('capturing', fn (): CapturingExporter => $exporter);

    $subscriber = app(LaravelAiEventSubscriber::class);
    $registry = app(TraceRegistry::class);

    $subscriber->handlePrompting(new FakePromptingEvent);
    $subscriber->handlePrompting(new FakePromptingEvent(invocationId: ''));
    $subscriber->handleInvokingTool(new FakeInvokingToolEvent(invocationId: ''));
    $subscriber->handleToolInvoked(new FakeToolInvokedEvent(invocationId: ''));
    $subscriber->handlePrompted(new FakePromptedEvent(invocationId: ''));

    expect($registry->trace('invocation-123'))->toBeNull()
        ->and($registry->trace(''))->toBeNull()
        ->and($exporter->traces)->toBe([]);
});

it('knows about all laravel ai lifecycle event classes', function (): void {
    expect(EventClassMap::events())->toContain(
        'Laravel\\Ai\\Events\\PromptingAgent',
        'Laravel\\Ai\\Events\\StreamingAgent',
        'Laravel\\Ai\\Events\\AgentPrompted',
        'Laravel\\Ai\\Events\\AgentStreamed',
        'Laravel\\Ai\\Events\\InvokingTool',
        'Laravel\\Ai\\Events\\ToolInvoked',
    );
});
