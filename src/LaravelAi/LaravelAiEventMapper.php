<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\LaravelAi;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use ReflectionMethod;
use Stringable;
use Tracefast\LaravelAiObservability\Context\ObservationContext;
use Tracefast\LaravelAiObservability\Support\Arr;
use Traversable;
use UnitEnum;

final class LaravelAiEventMapper
{
    private const MaxSanitizationDepth = 8;

    public function __construct(
        private readonly ?ObservationContext $context = null,
    ) {}

    /**
     * @return array{
     *     invocation_id: ?string,
     *     name: string,
     *     input: mixed,
     *     attributes: array<string, mixed>,
     *     llm_span: array{name: string, input: mixed, attributes: array<string, mixed>}
     * }
     */
    public function prompting(object $event): array
    {
        $prompt = $this->value($event, ['prompt']);
        $eventInput = $this->value($event, ['input']);
        $agent = $this->value($event, ['agent']) ?? $this->value($prompt, ['agent']);
        $invocationId = $this->stringValue(
            $this->value($event, ['invocationId', 'invocation_id'])
                ?? $this->value($prompt, ['invocationId', 'invocation_id'])
        );
        $provider = $this->providerName(
            $this->value($event, ['provider'])
                ?? $this->value($prompt, ['provider'])
                ?? $this->value($agent, ['provider'])
        );
        $model = $this->stringValue(
            $this->value($event, ['model', 'modelName', 'model_name'])
                ?? $this->value($prompt, ['model', 'modelName', 'model_name'])
                ?? $this->value($agent, ['model', 'modelName', 'model_name'])
        );
        $inputMessages = $this->normalizedPromptMessages($prompt, $agent, $eventInput);
        $promptText = $this->promptText($prompt, $eventInput);
        $promptMessages = $this->messagesJson($inputMessages);
        $input = $this->promptInput($prompt, $eventInput);

        return [
            'invocation_id' => $invocationId,
            'name' => $this->name($event, $agent, 'agent'),
            'input' => $input,
            'attributes' => $this->attributes([
                'openinference.span.kind' => 'AGENT',
                'tracefast.ai.invocation_id' => $invocationId,
            ]),
            'llm_span' => [
                'name' => $this->llmSpanName($model),
                'input' => $input,
                'attributes' => $this->attributes(array_merge([
                    'openinference.span.kind' => 'LLM',
                    'llm.system' => $this->llmSystem($provider),
                    'tracefast.ai.invocation_id' => $invocationId,
                    'llm.provider' => $provider,
                    'llm.model_name' => $model,
                    'gen_ai.operation.name' => $invocationId !== null ? 'chat' : null,
                    'gen_ai.system' => $provider,
                    'gen_ai.provider.name' => $provider,
                    'gen_ai.request.model' => $model,
                    'gen_ai.prompt' => $promptText,
                    'gen_ai.prompt_json' => $promptMessages,
                ], $this->messageAttributes('llm.input_messages', $inputMessages))),
            ],
        ];
    }

    /**
     * @return array{
     *     invocation_id: ?string,
     *     output: mixed,
     *     attributes: array<string, mixed>,
     *     llm_span: array{output: mixed, attributes: array<string, mixed>}
     * }
     */
    public function prompted(object $event): array
    {
        $prompt = $this->value($event, ['prompt']);
        $response = $this->value($event, ['response']);
        $agent = $this->value($event, ['agent'])
            ?? $this->value($prompt, ['agent'])
            ?? $this->value($response, ['agent']);
        $usage = $this->value($event, ['usage']) ?? $this->value($response, ['usage']);
        $meta = $this->value($event, ['meta']) ?? $this->value($response, ['meta']);
        $provider = $this->providerName(
            $this->value($event, ['provider'])
                ?? $this->value($meta, ['provider'])
                ?? $this->value($prompt, ['provider'])
                ?? $this->value($agent, ['provider'])
        );
        $model = $this->stringValue(
            $this->value($event, ['model', 'modelName', 'model_name'])
                ?? $this->value($meta, ['model', 'modelName', 'model_name'])
                ?? $this->value($prompt, ['model', 'modelName', 'model_name'])
                ?? $this->value($agent, ['model', 'modelName', 'model_name'])
        );
        $promptTokens = $this->value($usage, ['promptTokens', 'prompt_tokens', 'inputTokens', 'input_tokens']);
        $completionTokens = $this->value($usage, ['completionTokens', 'completion_tokens', 'outputTokens', 'output_tokens']);
        $totalTokens = $this->tokenTotal($promptTokens, $completionTokens);
        $responseType = $this->stringValue($this->value($event, ['responseType', 'response_type', 'type']) ?? $this->value($response, ['responseType', 'response_type', 'type']))
            ?? (is_object($response) ? class_basename($response) : null);
        $conversationId = $this->stringValue(
            $this->value($event, ['conversationId', 'conversation_id'])
                ?? $this->value($response, ['conversationId', 'conversation_id'])
        );
        $output = $this->responseOutput($response, $this->value($event, ['output']));
        $completion = $this->responseText($response, $this->value($event, ['output']));
        $outputMessages = $this->responseMessages($response, $completion);

        return [
            'invocation_id' => $this->stringValue(
                $this->value($event, ['invocationId', 'invocation_id'])
                    ?? $this->value($response, ['invocationId', 'invocation_id'])
                    ?? $this->value($prompt, ['invocationId', 'invocation_id'])
            ),
            'output' => $output,
            'attributes' => $this->attributes([
                'tracefast.ai.conversation_id' => $conversationId,
                'tracefast.ai.response_type' => $responseType,
            ]),
            'llm_span' => [
                'output' => $output,
                'attributes' => $this->attributes(array_merge([
                    'openinference.span.kind' => 'LLM',
                    'llm.system' => $this->llmSystem($provider),
                    'llm.provider' => $provider,
                    'llm.model_name' => $model,
                    'llm.token_count.prompt' => $promptTokens,
                    'llm.token_count.completion' => $completionTokens,
                    'llm.token_count.total' => $totalTokens,
                    'gen_ai.system' => $provider,
                    'gen_ai.provider.name' => $provider,
                    'gen_ai.response.model' => $model,
                    'gen_ai.completion' => $completion,
                    'gen_ai.usage.input_tokens' => $promptTokens,
                    'gen_ai.usage.output_tokens' => $completionTokens,
                    'tracefast.ai.conversation_id' => $conversationId,
                    'tracefast.ai.response_type' => $responseType,
                ], $this->messageAttributes('llm.output_messages', $outputMessages))),
            ],
        ];
    }

    /**
     * @return array{invocation_id: ?string, tool_invocation_id: ?string, name: string, input: mixed, attributes: array<string, mixed>}
     */
    public function invokingTool(object $event): array
    {
        $tool = $this->value($event, ['tool']);
        $toolInvocationId = $this->stringValue($this->value($event, ['toolInvocationId', 'tool_invocation_id', 'toolCallId', 'tool_call_id']));
        $name = $this->name($event, $tool, 'tool');

        return [
            'invocation_id' => $this->stringValue($this->value($event, ['invocationId', 'invocation_id'])),
            'tool_invocation_id' => $toolInvocationId,
            'name' => $name,
            'input' => $this->captured($this->value($event, ['arguments', 'args', 'input'])),
            'attributes' => $this->attributes([
                'openinference.span.kind' => 'TOOL',
                'tool.name' => $name,
                'tool.id' => $toolInvocationId,
                'tool.call.id' => $toolInvocationId,
            ]),
        ];
    }

    /**
     * @return array{invocation_id: ?string, tool_invocation_id: ?string, output: mixed}
     */
    public function toolInvoked(object $event): array
    {
        return [
            'invocation_id' => $this->stringValue($this->value($event, ['invocationId', 'invocation_id'])),
            'tool_invocation_id' => $this->stringValue($this->value($event, ['toolInvocationId', 'tool_invocation_id', 'toolCallId', 'tool_call_id'])),
            'output' => $this->captured($this->value($event, ['result', 'output'])),
        ];
    }

    /**
     * @param  list<string>  $names
     */
    private function value(mixed $target, array $names): mixed
    {
        if (! is_object($target)) {
            return null;
        }

        $properties = get_object_vars($target);

        foreach ($names as $name) {
            if (array_key_exists($name, $properties)) {
                return $properties[$name];
            }

            if ($this->canCall($target, $name)) {
                return $target->{$name}();
            }
        }

        return null;
    }

    private function canCall(object $target, string $method): bool
    {
        if (! method_exists($target, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($target, $method);

        return $reflection->isPublic() && $reflection->getNumberOfRequiredParameters() === 0;
    }

    private function name(object $event, mixed $subject, string $fallback): string
    {
        $name = $this->stringValue($this->value($event, ['name']));

        if ($name !== null) {
            return $name;
        }

        $name = $this->stringValue($this->value($subject, ['name']));

        if ($name !== null) {
            return $name;
        }

        if (is_object($subject)) {
            return class_basename($subject);
        }

        return $fallback === 'agent' ? class_basename($event) : $fallback;
    }

    private function llmSpanName(?string $model): string
    {
        if ($model === null || trim($model) === '') {
            return 'chat';
        }

        return "chat {$model}";
    }

    private function promptInput(mixed $prompt, mixed $fallback): mixed
    {
        if (! $this->capturesContent()) {
            return null;
        }

        $value = $this->value($prompt, ['input', 'prompt', 'text', 'content', 'message']);
        $agent = $this->value($prompt, ['agent']);
        $instructions = $this->value($prompt, ['instructions']) ?? $this->value($agent, ['instructions']);
        $attachments = $this->value($prompt, ['attachments']);
        $messages = $this->promptMessages($prompt, $agent, $value, $attachments, $instructions);

        if ($instructions !== null || $messages !== null || $attachments !== null) {
            return Arr::withoutNulls([
                'value' => $this->serializable($value),
                'instructions' => $this->serializable($instructions),
                'messages' => $this->serializable($messages),
                'attachments' => $this->serializable($attachments),
            ]);
        }

        return $this->content($prompt) ?? $this->serializable($fallback);
    }

    private function promptMessages(mixed $prompt, mixed $agent, mixed $value, mixed $attachments, mixed $instructions): mixed
    {
        $promptMessages = $this->value($prompt, ['messages']);

        if ($promptMessages !== null) {
            return $promptMessages;
        }

        $agentMessages = $this->value($agent, ['messages']);

        if ($agentMessages === null && $attachments === null && $instructions === null) {
            return null;
        }

        $messages = $this->list($agentMessages);

        if ($value !== null || $attachments !== null) {
            $messages[] = Arr::withoutNulls([
                'role' => 'user',
                'content' => $value,
                'attachments' => $attachments,
            ]);
        }

        return $messages === [] ? null : $messages;
    }

    private function promptText(mixed $prompt, mixed $fallback): ?string
    {
        if (! $this->capturesContent()) {
            return null;
        }

        return $this->stringValue(
            $this->value($prompt, ['input', 'prompt', 'text', 'content', 'message'])
                ?? $fallback
        );
    }

    private function responseOutput(mixed $response, mixed $fallback): mixed
    {
        if (! $this->capturesContent()) {
            return null;
        }

        $toolCalls = $this->value($response, ['toolCalls', 'tool_calls']);
        $toolResults = $this->value($response, ['toolResults', 'tool_results']);
        $steps = $this->value($response, ['steps']);

        if ($toolCalls !== null || $toolResults !== null || $steps !== null) {
            return [
                'value' => $this->serializable($this->value($response, ['output', 'text', 'content', 'message', 'result']) ?? $fallback),
                'tool_calls' => $this->serializable($toolCalls ?? []),
                'tool_results' => $this->serializable($toolResults ?? []),
                'steps' => $this->serializable($steps ?? []),
            ];
        }

        return $this->content($response) ?? $this->serializable($fallback);
    }

    private function responseText(mixed $response, mixed $fallback): ?string
    {
        if (! $this->capturesContent()) {
            return null;
        }

        return $this->stringValue(
            $this->value($response, ['output', 'text', 'content', 'message', 'result'])
                ?? $fallback
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedPromptMessages(mixed $prompt, mixed $agent, mixed $fallback): array
    {
        if (! $this->capturesContent()) {
            return [];
        }

        $value = $this->value($prompt, ['input', 'prompt', 'text', 'content', 'message']);
        $attachments = $this->value($prompt, ['attachments']);
        $instructions = $this->value($prompt, ['instructions']) ?? $this->value($agent, ['instructions']);
        $messages = $this->normalizedMessages(
            $this->promptMessages($prompt, $agent, $value ?? $fallback, $attachments, $instructions),
        );

        if ($messages !== []) {
            return $this->withInstructionMessage($messages, $instructions);
        }

        $content = $this->stringValue($value ?? $fallback);

        if ($content === null) {
            return [];
        }

        return $this->withInstructionMessage([
            [
                'role' => 'user',
                'content' => $content,
            ],
        ], $instructions);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function responseMessages(mixed $response, ?string $completion): array
    {
        if (! $this->capturesContent()) {
            return [];
        }

        $message = [
            'role' => 'assistant',
            'content' => $completion,
            'tool_calls' => $this->serializable($this->value($response, ['toolCalls', 'tool_calls'])),
        ];

        return Arr::withoutNulls($message) === ['role' => 'assistant'] ? [] : [Arr::withoutNulls($message)];
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    private function messagesJson(array $messages): ?string
    {
        if ($messages === []) {
            return null;
        }

        return json_encode($messages, JSON_THROW_ON_ERROR);
    }

    private function captured(mixed $value): mixed
    {
        if (! $this->capturesContent()) {
            return null;
        }

        return $this->serializable($value);
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function normalizedMessages(mixed $messages): array
    {
        $normalized = [];

        foreach ($this->list($messages) as $message) {
            $role = $this->stringValue($this->value($message, ['role']) ?? (is_array($message) ? ($message['role'] ?? null) : null));
            $content = $this->stringValue($this->value($message, ['content']) ?? (is_array($message) ? ($message['content'] ?? null) : null));

            if ($role === null || $content === null) {
                continue;
            }

            $normalized[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    private function withInstructionMessage(array $messages, mixed $instructions): array
    {
        $content = $this->stringValue($instructions);

        if ($content === null) {
            return $messages;
        }

        foreach ($messages as $message) {
            if (($message['role'] ?? null) === 'system') {
                return $messages;
            }
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => $content,
        ]);

        return $messages;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function messageAttributes(string $prefix, array $messages): array
    {
        $attributes = [];

        foreach ($messages as $messageIndex => $message) {
            $base = "{$prefix}.{$messageIndex}.message";

            foreach (['role', 'content', 'name', 'tool_call_id'] as $key) {
                $value = $this->stringValue($message[$key] ?? null);

                if ($value !== null) {
                    $attributes["{$base}.{$key}"] = $value;
                }
            }

            foreach ($this->list($message['tool_calls'] ?? null) as $toolCallIndex => $toolCall) {
                $toolCallBase = "{$base}.tool_calls.{$toolCallIndex}.tool_call";
                $toolCallId = $this->stringValue(
                    $this->value($toolCall, ['id', 'toolCallId', 'tool_call_id'])
                        ?? (is_array($toolCall) ? ($toolCall['id'] ?? $toolCall['toolCallId'] ?? $toolCall['tool_call_id'] ?? null) : null)
                );
                $function = $this->value($toolCall, ['function'])
                    ?? (is_array($toolCall) ? ($toolCall['function'] ?? null) : null);
                $functionName = $this->stringValue(
                    $this->value($function, ['name'])
                        ?? $this->value($toolCall, ['name'])
                        ?? (is_array($function) ? ($function['name'] ?? null) : null)
                        ?? (is_array($toolCall) ? ($toolCall['name'] ?? null) : null)
                );
                $arguments = $this->value($function, ['arguments'])
                    ?? $this->value($toolCall, ['arguments'])
                    ?? (is_array($function) ? ($function['arguments'] ?? null) : null)
                    ?? (is_array($toolCall) ? ($toolCall['arguments'] ?? null) : null);

                if ($toolCallId !== null) {
                    $attributes["{$toolCallBase}.id"] = $toolCallId;
                }

                if ($functionName !== null) {
                    $attributes["{$toolCallBase}.function.name"] = $functionName;
                }

                if ($arguments !== null) {
                    $attributes["{$toolCallBase}.function.arguments"] = $this->jsonAttribute($arguments);
                }
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function attributes(array $attributes): array
    {
        return array_merge(
            Arr::withoutNulls($attributes),
            $this->context?->attributes() ?? [],
        );
    }

    private function capturesContent(): bool
    {
        return config('ai-observability.capture.content', 'full') !== 'off';
    }

    private function content(mixed $target): mixed
    {
        $content = $this->value($target, ['output', 'text', 'content', 'prompt', 'message', 'messages', 'result']);

        if ($content !== null) {
            return $this->serializable($content);
        }

        return $this->serializable($target);
    }

    private function providerName(mixed $provider): ?string
    {
        if ($provider === null) {
            return null;
        }

        return $this->stringValue($provider)
            ?? $this->stringValue($this->value($provider, ['name', 'value']))
            ?? (is_object($provider) ? class_basename($provider) : null);
    }

    private function llmSystem(?string $provider): string
    {
        if ($provider === null || trim($provider) === '') {
            return 'unknown';
        }

        return strtolower(trim($provider));
    }

    private function tokenTotal(mixed $promptTokens, mixed $completionTokens): ?int
    {
        if (! is_numeric($promptTokens) || ! is_numeric($completionTokens)) {
            return null;
        }

        return (int) $promptTokens + (int) $completionTokens;
    }

    private function jsonAttribute(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($this->serializable($value), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if ($value instanceof Traversable) {
            return array_values(iterator_to_array($value, false));
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  array<int, true>  $seen
     */
    private function serializable(mixed $value, int $depth = 0, array $seen = []): mixed
    {
        if ($depth >= self::MaxSanitizationDepth) {
            return '[max-depth]';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = $this->serializable($item, $depth + 1, $seen);
            }

            return $sanitized;
        }

        if (is_resource($value)) {
            return sprintf('[resource:%s]', get_resource_type($value));
        }

        if ($value instanceof Closure) {
            return '[closure]';
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Arrayable) {
            return $this->serializable($value->toArray(), $depth + 1, $seen);
        }

        if ($value instanceof JsonSerializable) {
            return $this->serializable($value->jsonSerialize(), $depth + 1, $seen);
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value)) {
            $objectId = spl_object_id($value);

            if (isset($seen[$objectId])) {
                return ['class' => class_basename($value), 'recursive' => true];
            }

            $properties = get_object_vars($value);

            if ($properties === []) {
                return ['class' => class_basename($value)];
            }

            $seen[$objectId] = true;
            $sanitized = [];

            foreach ($properties as $key => $property) {
                $sanitized[$key] = $this->serializable($property, $depth + 1, $seen);
            }

            return $sanitized;
        }

        return null;
    }
}
