<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

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
        public int $cacheReadInputTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public int $reasoningTokens = 0,
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

final class FakeStructuredAgent implements HasStructuredOutput
{
    public string $name = 'Evaluation Agent';

    public string $provider = 'anthropic';

    public string $model = 'claude-opus-4-6';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'score' => $schema->integer()->description('Overall score')->required(),
            'summary' => $schema->string()->required(),
        ];
    }
}

final class FakeWeatherTool implements Tool
{
    public function name(): string
    {
        return 'lookup_weather';
    }

    public function description(): Stringable|string
    {
        return 'Look up the weather for a city.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'sunny';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required(),
        ];
    }
}

final class FakeToolAgent implements HasTools
{
    public string $name = 'Weather Agent';

    public string $provider = 'openai';

    public string $model = 'gpt-4.1-mini';

    /**
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return [new FakeWeatherTool];
    }
}

final class FakeStructuredPromptingEvent
{
    public FakePrompt $prompt;

    public FakeStructuredAgent $agent;

    public function __construct(
        public string $invocationId = 'invocation-structured',
    ) {
        $this->prompt = new FakePrompt('Evaluate this candidate.');
        $this->agent = new FakeStructuredAgent;
    }
}

final class FakeToolPromptingEvent
{
    public FakePrompt $prompt;

    public FakeToolAgent $agent;

    public function __construct(
        public string $invocationId = 'invocation-tools',
    ) {
        $this->prompt = new FakePrompt('What is the weather?');
        $this->agent = new FakeToolAgent;
    }
}
