<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Tests\Fixtures;

final class FakeAgent
{
    public function __construct(
        public string $name = 'Screening Agent',
        public string $provider = 'openai',
        public string $model = 'gpt-4.1-mini',
    ) {}
}

final class FakePrompt
{
    public function __construct(
        public string $content,
    ) {}
}

final class FakeUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
    ) {}
}

final class FakeResponse
{
    public function __construct(
        public string $output,
        public FakeUsage $usage,
        public string $type = 'text',
    ) {}
}

final class FakeResponseWithoutType
{
    public function __construct(
        public string $output,
        public FakeUsage $usage,
    ) {}
}

final class FakePromptingEvent
{
    public FakePrompt $prompt;

    public function __construct(
        public string $invocationId = 'invocation-123',
        public string $conversationId = 'conversation-456',
    ) {
        $this->prompt = new FakePrompt('Summarize this candidate.');
    }

    public function agent(): FakeAgent
    {
        return new FakeAgent;
    }
}

final class FakePromptedEvent
{
    public FakeAgent $agent;

    public function __construct(
        public string $invocationId = 'invocation-123',
        public string $conversationId = 'conversation-456',
    ) {
        $this->agent = new FakeAgent;
    }

    public function response(): FakeResponse
    {
        return new FakeResponse(
            output: 'The candidate is a strong fit.',
            usage: new FakeUsage(promptTokens: 17, completionTokens: 8),
        );
    }
}

final class FakePromptedEventWithoutResponseType
{
    public FakeAgent $agent;

    public function __construct(
        public string $invocationId = 'invocation-123',
        public string $conversationId = 'conversation-456',
    ) {
        $this->agent = new FakeAgent;
    }

    public function response(): FakeResponseWithoutType
    {
        return new FakeResponseWithoutType(
            output: 'The candidate is a strong fit.',
            usage: new FakeUsage(promptTokens: 17, completionTokens: 8),
        );
    }
}

final class FakeTool
{
    public function __construct(
        public string $name = 'lookup_candidate',
    ) {}
}

final class FakeInvokingToolEvent
{
    public function __construct(
        public string $invocationId = 'invocation-123',
        public string $toolInvocationId = 'tool-call-789',
        public FakeTool $tool = new FakeTool,
        public array $arguments = ['candidate_id' => 42],
    ) {}
}

final class FakeToolInvokedEvent
{
    public function __construct(
        public string $invocationId = 'invocation-123',
        public string $toolInvocationId = 'tool-call-789',
        public array $result = ['name' => 'Ada Lovelace'],
    ) {}
}

final class FakeNestedToolOutput
{
    public function __construct(
        public string $name,
        public object $nested,
    ) {}
}
