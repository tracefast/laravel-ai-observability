<?php

declare(strict_types=1);

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Tracefast\LaravelAiObservability\LaravelAi\LaravelAiEventMapper;

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
            'openinference.span.kind' => 'agent',
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
                'openinference.span.kind' => 'llm',
                'tracefast.ai.invocation_id' => 'invocation-rich',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'gen_ai.operation.name' => 'chat',
                'gen_ai.system' => 'anthropic',
                'gen_ai.provider.name' => 'anthropic',
                'gen_ai.request.model' => 'claude-4-sonnet',
                'gen_ai.prompt' => 'Summarize the latest interview notes.',
                'gen_ai.prompt_json' => json_encode([
                    ['role' => 'system', 'content' => 'Be concise.'],
                    ['role' => 'user', 'content' => 'Summarize the latest interview notes.'],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
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

it('normalizes laravel ai message objects into genai prompt json', function (): void {
    config()->set('ai-observability.capture.content', 'full');

    $payload = (new LaravelAiEventMapper)->prompting(new ObjectMessagePromptingEvent);
    $messages = json_decode($payload['llm_span']['attributes']['gen_ai.prompt_json'], true, flags: JSON_THROW_ON_ERROR);

    expect($messages)->toBe([
        ['role' => 'assistant', 'content' => 'Previous summary is available.'],
        ['role' => 'user', 'content' => 'Summarize the uploaded resume.'],
    ]);
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
                'openinference.span.kind' => 'llm',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'llm.token_count.prompt' => 31,
                'llm.token_count.completion' => 12,
                'gen_ai.system' => 'anthropic',
                'gen_ai.provider.name' => 'anthropic',
                'gen_ai.response.model' => 'claude-4-sonnet',
                'gen_ai.completion' => 'Ada is a strong match.',
                'gen_ai.usage.input_tokens' => 31,
                'gen_ai.usage.output_tokens' => 12,
                'tracefast.ai.conversation_id' => 'conversation-rich',
                'tracefast.ai.response_type' => 'text',
            ],
        ],
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
            'openinference.span.kind' => 'tool',
            'tool.name' => 'lookup_candidate',
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
            'openinference.span.kind' => 'agent',
            'tracefast.ai.invocation_id' => 'invocation-rich',
        ],
        'llm_span' => [
            'name' => 'chat claude-4-sonnet',
            'input' => null,
            'attributes' => [
                'openinference.span.kind' => 'llm',
                'tracefast.ai.invocation_id' => 'invocation-rich',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'gen_ai.operation.name' => 'chat',
                'gen_ai.system' => 'anthropic',
                'gen_ai.provider.name' => 'anthropic',
                'gen_ai.request.model' => 'claude-4-sonnet',
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
                'openinference.span.kind' => 'llm',
                'llm.provider' => 'anthropic',
                'llm.model_name' => 'claude-4-sonnet',
                'llm.token_count.prompt' => 31,
                'llm.token_count.completion' => 12,
                'gen_ai.system' => 'anthropic',
                'gen_ai.provider.name' => 'anthropic',
                'gen_ai.response.model' => 'claude-4-sonnet',
                'gen_ai.usage.input_tokens' => 31,
                'gen_ai.usage.output_tokens' => 12,
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
            'openinference.span.kind' => 'tool',
            'tool.name' => 'lookup_candidate',
            'tool.call.id' => 'call-1',
        ],
    ])->and($mapper->toolInvoked(new RichToolInvokedEvent))->toMatchArray([
        'invocation_id' => 'invocation-rich',
        'tool_invocation_id' => 'call-1',
        'output' => null,
    ]);
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
