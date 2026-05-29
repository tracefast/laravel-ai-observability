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
     * @return array{invocation_id: ?string, name: string, input: mixed, attributes: array<string, mixed>}
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
        $promptText = $this->promptText($prompt, $eventInput);
        $promptMessages = $this->promptJson($prompt, $agent, $eventInput);

        return [
            'invocation_id' => $invocationId,
            'name' => $this->name($event, $agent, 'agent'),
            'input' => $this->promptInput($prompt, $eventInput),
            'attributes' => $this->attributes([
                'openinference.span.kind' => 'agent',
                'tracefast.ai.invocation_id' => $invocationId,
                'llm.provider' => $provider,
                'llm.model_name' => $model,
                'gen_ai.operation.name' => $invocationId !== null ? 'chat' : null,
                'gen_ai.provider.name' => $provider,
                'gen_ai.request.model' => $model,
                'gen_ai.prompt' => $promptText,
                'gen_ai.prompt_json' => $promptMessages,
            ]),
        ];
    }

    /**
     * @return array{invocation_id: ?string, output: mixed, attributes: array<string, mixed>}
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
        $responseType = $this->stringValue($this->value($event, ['responseType', 'response_type', 'type']) ?? $this->value($response, ['responseType', 'response_type', 'type']))
            ?? (is_object($response) ? class_basename($response) : null);

        return [
            'invocation_id' => $this->stringValue(
                $this->value($event, ['invocationId', 'invocation_id'])
                    ?? $this->value($response, ['invocationId', 'invocation_id'])
                    ?? $this->value($prompt, ['invocationId', 'invocation_id'])
            ),
            'output' => $this->responseOutput($response, $this->value($event, ['output'])),
            'attributes' => $this->attributes([
                'llm.provider' => $provider,
                'llm.model_name' => $model,
                'llm.token_count.prompt' => $promptTokens,
                'llm.token_count.completion' => $completionTokens,
                'gen_ai.provider.name' => $provider,
                'gen_ai.response.model' => $model,
                'gen_ai.completion' => $this->responseText($response, $this->value($event, ['output'])),
                'gen_ai.usage.input_tokens' => $promptTokens,
                'gen_ai.usage.output_tokens' => $completionTokens,
                'tracefast.ai.conversation_id' => $this->stringValue(
                    $this->value($event, ['conversationId', 'conversation_id'])
                        ?? $this->value($response, ['conversationId', 'conversation_id'])
                ),
                'tracefast.ai.response_type' => $responseType,
            ]),
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
                'openinference.span.kind' => 'tool',
                'tool.name' => $name,
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

    private function promptJson(mixed $prompt, mixed $agent, mixed $fallback): ?string
    {
        if (! $this->capturesContent()) {
            return null;
        }

        $value = $this->value($prompt, ['input', 'prompt', 'text', 'content', 'message']);
        $attachments = $this->value($prompt, ['attachments']);
        $instructions = $this->value($prompt, ['instructions']) ?? $this->value($agent, ['instructions']);
        $messages = $this->promptMessages($prompt, $agent, $value ?? $fallback, $attachments, $instructions);
        $messages = $this->normalizedMessages($messages);

        if ($messages === []) {
            return null;
        }

        return json_encode($messages, JSON_THROW_ON_ERROR);
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
