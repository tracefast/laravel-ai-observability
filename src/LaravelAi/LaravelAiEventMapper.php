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
use Tracefast\LaravelAiObservability\Support\Arr;
use UnitEnum;

final class LaravelAiEventMapper
{
    private const MaxSanitizationDepth = 8;

    /**
     * @return array{invocation_id: ?string, name: string, input: mixed, attributes: array<string, mixed>}
     */
    public function prompting(object $event): array
    {
        $prompt = $this->value($event, ['prompt']);
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

        return [
            'invocation_id' => $invocationId,
            'name' => $this->name($event, $agent, 'agent'),
            'input' => $this->content($prompt) ?? $this->value($event, ['input']),
            'attributes' => Arr::withoutNulls([
                'openinference.span.kind' => 'agent',
                'tracefast.ai.invocation_id' => $invocationId,
                'llm.provider' => $provider,
                'llm.model_name' => $model,
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
        $responseType = $this->stringValue($this->value($event, ['responseType', 'response_type', 'type']) ?? $this->value($response, ['responseType', 'response_type', 'type']))
            ?? (is_object($response) ? class_basename($response) : null);

        return [
            'invocation_id' => $this->stringValue(
                $this->value($event, ['invocationId', 'invocation_id'])
                    ?? $this->value($response, ['invocationId', 'invocation_id'])
                    ?? $this->value($prompt, ['invocationId', 'invocation_id'])
            ),
            'output' => $this->content($response) ?? $this->value($event, ['output']),
            'attributes' => Arr::withoutNulls([
                'llm.provider' => $this->providerName(
                    $this->value($event, ['provider'])
                        ?? $this->value($meta, ['provider'])
                        ?? $this->value($prompt, ['provider'])
                        ?? $this->value($agent, ['provider'])
                ),
                'llm.model_name' => $this->stringValue(
                    $this->value($event, ['model', 'modelName', 'model_name'])
                        ?? $this->value($meta, ['model', 'modelName', 'model_name'])
                        ?? $this->value($prompt, ['model', 'modelName', 'model_name'])
                        ?? $this->value($agent, ['model', 'modelName', 'model_name'])
                ),
                'llm.token_count.prompt' => $this->value($usage, ['promptTokens', 'prompt_tokens', 'inputTokens', 'input_tokens']),
                'llm.token_count.completion' => $this->value($usage, ['completionTokens', 'completion_tokens', 'outputTokens', 'output_tokens']),
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
            'input' => $this->serializable($this->value($event, ['arguments', 'args', 'input'])),
            'attributes' => Arr::withoutNulls([
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
            'output' => $this->serializable($this->value($event, ['result', 'output'])),
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

    private function stringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
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
