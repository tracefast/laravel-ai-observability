<?php

declare(strict_types=1);

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventMapper;
use Tracefast\LaravelAiObservability\Tests\Fixtures\FakeStructuredPromptingEvent;

it('captures prompt text and messages in full content mode', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $payload = (new LaravelAiEventMapper)->prompting(new RichPromptingEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'name' => 'Research Agent',
        'input' => [
            'value' => 'Summarize the latest interview notes.',
            'messages' => [
                ['role' => 'system', 'content' => 'Be concise.'],
                ['role' => 'user', 'content' => 'Summarize the latest interview notes.'],
            ],
        ],
        'attributes' => [
            'openinference.span.kind' => 'AGENT',
            'tracefast.ai.invocation_id' => 'invocation-rich',
        ],
        'llm_span' => [
            'name' => 'chat claude-4-sonnet',
            'input' => [
                'value' => 'Summarize the latest interview notes.',
                'messages' => [
                    ['role' => 'system', 'content' => 'Be concise.'],
                    ['role' => 'user', 'content' => 'Summarize the latest interview notes.'],
                ],
            ],
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'anthropic',
                'tracefast.ai.invocation_id' => 'invocation-rich',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'llm.input_messages.0.message.role' => 'system',
                'llm.input_messages.0.message.content' => 'Be concise.',
                'llm.input_messages.1.message.role' => 'user',
                'llm.input_messages.1.message.content' => 'Summarize the latest interview notes.',
            ],
        ],
    ]);

    expect($payload['llm_span']['attributes'])->not->toHaveKeys([
        'gen_ai.operation.name',
        'gen_ai.system',
        'gen_ai.provider.name',
        'gen_ai.request.model',
        'gen_ai.input.messages',
        'gen_ai.prompt',
        'gen_ai.prompt_json',
    ]);
});

it('captures real laravel ai prompt instructions conversation messages and attachments', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $payload = (new LaravelAiEventMapper)->prompting(new RealPromptingEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-real',
        'name' => 'Real Agent',
        'input' => [
            'value' => 'Summarize the uploaded resume.',
            'instructions' => 'Use the hiring rubric and be direct.',
            'messages' => [
                [
                    'role' => 'assistant',
                    'content' => 'Previous summary is available.',
                ],
                [
                    'role' => 'user',
                    'content' => 'Summarize the uploaded resume.',
                    'attachments' => [
                        [
                            'name' => 'resume.pdf',
                            'mimeType' => 'application/pdf',
                        ],
                    ],
                ],
            ],
            'attachments' => [
                [
                    'name' => 'resume.pdf',
                    'mimeType' => 'application/pdf',
                ],
            ],
        ],
    ]);
});

it('normalizes laravel ai message objects into openinference messages', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $payload = (new LaravelAiEventMapper)->prompting(new ObjectMessagePromptingEvent);
    $attributes = $payload['llm_span']['attributes'];

    expect($attributes)->toMatchArray([
        'llm.input_messages.0.message.role' => 'system',
        'llm.input_messages.0.message.content' => 'Be direct.',
        'llm.input_messages.1.message.role' => 'assistant',
        'llm.input_messages.1.message.content' => 'Previous summary is available.',
        'llm.input_messages.2.message.role' => 'user',
        'llm.input_messages.2.message.content' => 'Summarize the uploaded resume.',
    ])->not->toHaveKey('gen_ai.input.messages');
});

it('captures response value tool calls tool results and steps in full content mode', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $payload = (new LaravelAiEventMapper)->prompted(new RichPromptedEvent);

    expect($payload)->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'output' => [
            'value' => 'Ada is a strong match.',
            'tool_calls' => [
                [
                    'id' => 'call-1',
                    'name' => 'lookup_candidate',
                    'arguments' => ['candidate_id' => 42],
                ],
            ],
            'tool_results' => [
                [
                    'tool_call_id' => 'call-1',
                    'result' => ['name' => 'Ada Lovelace'],
                ],
            ],
            'steps' => [
                [
                    'type' => 'tool_call',
                    'tool_call_id' => 'call-1',
                    'content' => ['status' => 'complete'],
                ],
            ],
        ],
        'attributes' => [
            'tracefast.ai.conversation_id' => 'conversation-rich',
            'tracefast.ai.response_type' => 'text',
        ],
        'llm_span' => [
            'output' => [
                'value' => 'Ada is a strong match.',
                'tool_calls' => [
                    [
                        'id' => 'call-1',
                        'name' => 'lookup_candidate',
                        'arguments' => ['candidate_id' => 42],
                    ],
                ],
                'tool_results' => [
                    [
                        'tool_call_id' => 'call-1',
                        'result' => ['name' => 'Ada Lovelace'],
                    ],
                ],
                'steps' => [
                    [
                        'type' => 'tool_call',
                        'tool_call_id' => 'call-1',
                        'content' => ['status' => 'complete'],
                    ],
                ],
            ],
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'anthropic',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'llm.token_count.prompt' => 31,
                'llm.token_count.completion' => 12,
                'llm.token_count.total' => 43,
                'tracefast.ai.conversation_id' => 'conversation-rich',
                'tracefast.ai.response_type' => 'text',
                'llm.output_messages.0.message.role' => 'assistant',
                'llm.output_messages.0.message.content' => 'Ada is a strong match.',
                'llm.output_messages.0.message.tool_calls.0.tool_call.id' => 'call-1',
                'llm.output_messages.0.message.tool_calls.0.tool_call.function.name' => 'lookup_candidate',
                'llm.output_messages.0.message.tool_calls.0.tool_call.function.arguments' => '{"candidate_id":42}',
            ],
        ],
    ]);

    expect($payload['llm_span']['attributes'])->not->toHaveKeys([
        'gen_ai.system',
        'gen_ai.provider.name',
        'gen_ai.response.model',
        'gen_ai.usage.input_tokens',
        'gen_ai.usage.output_tokens',
        'gen_ai.output.messages',
        'gen_ai.completion',
    ]);
});

it('captures tool arguments and results in full content mode', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $mapper = new LaravelAiEventMapper;

    expect($mapper->invokingTool(new RichInvokingToolEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'tool_invocation_id' => 'call-1',
        'name' => 'lookup_candidate',
        'input' => [
            'candidate_id' => 42,
            'options' => ['include_notes' => true],
        ],
        'attributes' => [
            'openinference.span.kind' => 'TOOL',
            'tool.name' => 'lookup_candidate',
            'tool.id' => 'call-1',
            'tool.call.id' => 'call-1',
        ],
    ])->and($mapper->toolInvoked(new RichToolInvokedEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'tool_invocation_id' => 'call-1',
        'output' => [
            'name' => 'Ada Lovelace',
            'notes' => ['analytical', 'clear communicator'],
        ],
    ]);
});

it('omits content payloads in off mode while preserving metadata', function (): void {
    config()->set('ai-observability.capture.content', 'off');

    $mapper = new LaravelAiEventMapper;

    expect($mapper->prompting(new RichPromptingEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'name' => 'Research Agent',
        'input' => null,
        'attributes' => [
            'openinference.span.kind' => 'AGENT',
            'tracefast.ai.invocation_id' => 'invocation-rich',
        ],
        'llm_span' => [
            'name' => 'chat claude-4-sonnet',
            'input' => null,
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'anthropic',
                'tracefast.ai.invocation_id' => 'invocation-rich',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
            ],
        ],
    ])->and($mapper->prompted(new RichPromptedEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'output' => null,
        'attributes' => [
            'tracefast.ai.conversation_id' => 'conversation-rich',
            'tracefast.ai.response_type' => 'text',
        ],
        'llm_span' => [
            'output' => null,
            'attributes' => [
                'openinference.span.kind' => 'LLM',
                'llm.system' => 'anthropic',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'llm.token_count.prompt' => 31,
                'llm.token_count.completion' => 12,
                'llm.token_count.total' => 43,
                'tracefast.ai.conversation_id' => 'conversation-rich',
                'tracefast.ai.response_type' => 'text',
            ],
        ],
    ])->and($mapper->invokingTool(new RichInvokingToolEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'tool_invocation_id' => 'call-1',
        'name' => 'lookup_candidate',
        'input' => null,
        'attributes' => [
            'openinference.span.kind' => 'TOOL',
            'tool.name' => 'lookup_candidate',
            'tool.id' => 'call-1',
            'tool.call.id' => 'call-1',
        ],
    ])->and($mapper->toolInvoked(new RichToolInvokedEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'tool_invocation_id' => 'call-1',
        'output' => null,
    ]);

    expect($mapper->prompting(new FakeStructuredPromptingEvent)['llm_span']['attributes'])
        ->not->toHaveKey('llm.tools.0.tool.json_schema');
});

final class RichProvider
{
    public function name(): string
    {
        return 'anthropic';
    }
}

final class RichAgent
{
    public string $name = 'Research Agent';

    public string $modelName = 'claude-4-sonnet';

    public function provider(): RichProvider
    {
        return new RichProvider;
    }
}

final class RichPrompt
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function __construct(
        public string $prompt = 'Summarize the latest interview notes.',
        public array $messages = [
            ['role' => 'system', 'content' => 'Be concise.'],
            ['role' => 'user', 'content' => 'Summarize the latest interview notes.'],
        ],
    ) {}
}

final class RichUsage
{
    public function promptTokens(): int
    {
        return 31;
    }

    public function completionTokens(): int
    {
        return 12;
    }
}

final class RichResponse
{
    public string $output = 'Ada is a strong match.';

    public string $type = 'text';

    /**
     * @var list<array{id: string, name: string, arguments: array{candidate_id: int}}>
     */
    public array $toolCalls = [
        [
            'id' => 'call-1',
            'name' => 'lookup_candidate',
            'arguments' => ['candidate_id' => 42],
        ],
    ];

    /**
     * @var list<array{type: string, tool_call_id: string, content: array{status: string}}>
     */
    public array $steps = [
        [
            'type' => 'tool_call',
            'tool_call_id' => 'call-1',
            'content' => ['status' => 'complete'],
        ],
    ];

    public function usage(): RichUsage
    {
        return new RichUsage;
    }

    /**
     * @return list<array{tool_call_id: string, result: array{name: string}}>
     */
    public function toolResults(): array
    {
        return [
            [
                'tool_call_id' => 'call-1',
                'result' => ['name' => 'Ada Lovelace'],
            ],
        ];
    }
}

final class RichPromptingEvent
{
    public string $invocationId = 'invocation-rich';

    public function prompt(): RichPrompt
    {
        return new RichPrompt;
    }

    public function agent(): RichAgent
    {
        return new RichAgent;
    }
}

final class RealAttachment
{
    public function __construct(
        public string $name,
        public string $mimeType,
    ) {}
}

final class RealAgent
{
    public string $name = 'Real Agent';

    public string $model = 'gpt-4.1-mini';

    public function instructions(): string
    {
        return 'Use the hiring rubric and be direct.';
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function messages(): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => 'Previous summary is available.',
            ],
        ];
    }

    public function provider(): string
    {
        return 'openai';
    }
}

final class RealPrompt
{
    public RealAgent $agent;

    /**
     * @var list<RealAttachment>
     */
    public array $attachments;

    public function __construct(
        public string $prompt = 'Summarize the uploaded resume.',
    ) {
        $this->agent = new RealAgent;
        $this->attachments = [
            new RealAttachment('resume.pdf', 'application/pdf'),
        ];
    }
}

final class RealPromptingEvent
{
    public string $invocationId = 'invocation-real';

    public function prompt(): RealPrompt
    {
        return new RealPrompt;
    }
}

final class ObjectMessageAgent
{
    public string $name = 'Object Message Agent';

    public string $model = 'gpt-4.1-mini';

    /**
     * @return list<Message>
     */
    public function messages(): array
    {
        return [
            new AssistantMessage('Previous summary is available.'),
        ];
    }

    public function provider(): string
    {
        return 'openai';
    }

    public function instructions(): string
    {
        return 'Be direct.';
    }
}

final class ObjectMessagePrompt
{
    public ObjectMessageAgent $agent;

    public function __construct(
        public string $prompt = 'Summarize the uploaded resume.',
    ) {
        $this->agent = new ObjectMessageAgent;
    }
}

final class ObjectMessagePromptingEvent
{
    public string $invocationId = 'invocation-object-message';

    public function prompt(): ObjectMessagePrompt
    {
        return new ObjectMessagePrompt;
    }
}

final class RichPromptedEvent
{
    public string $invocationId = 'invocation-rich';

    public string $conversationId = 'conversation-rich';

    public function agent(): RichAgent
    {
        return new RichAgent;
    }

    public function response(): RichResponse
    {
        return new RichResponse;
    }
}

final class RichTool
{
    public string $name = 'lookup_candidate';
}

final class RichInvokingToolEvent
{
    public string $invocationId = 'invocation-rich';

    public string $toolInvocationId = 'call-1';

    /**
     * @var array{candidate_id: int, options: array{include_notes: bool}}
     */
    public array $arguments = [
        'candidate_id' => 42,
        'options' => ['include_notes' => true],
    ];

    public function tool(): RichTool
    {
        return new RichTool;
    }
}

final class RichToolInvokedEvent
{
    public string $invocationId = 'invocation-rich';

    public string $toolInvocationId = 'call-1';

    /**
     * @return array{name: string, notes: list<string>}
     */
    public function result(): array
    {
        return [
            'name' => 'Ada Lovelace',
            'notes' => ['analytical', 'clear communicator'],
        ];
    }
}
