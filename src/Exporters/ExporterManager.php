<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Exporters;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use Tracefast\LaravelAiObservability\Contracts\Exporter;

final class ExporterManager
{
    /**
     * @var array<string, Closure(Application, array<string, mixed>, string): Exporter>
     */
    private array $extensions = [];

    /**
     * @var array<string, Exporter>
     */
    private array $exporters = [];

    /**
     * @var array<string, true>
     */
    private array $resolving = [];

    public function __construct(
        private readonly Application $app,
    ) {}

    public function exporter(?string $name = null): Exporter
    {
        $name ??= (string) config('ai-observability.default', 'null');

        if (isset($this->exporters[$name])) {
            return $this->exporters[$name];
        }

        if (isset($this->resolving[$name])) {
            throw new InvalidArgumentException("Circular exporter stack detected while resolving [{$name}].");
        }

        $this->resolving[$name] = true;

        try {
            return $this->exporters[$name] = $this->resolve($name);
        } finally {
            unset($this->resolving[$name]);
        }
    }

    /**
     * @param  Closure(Application, array<string, mixed>, string): Exporter  $creator
     */
    public function extend(string $driver, Closure $creator): void
    {
        $this->extensions[$driver] = $creator;
    }

    private function resolve(string $name): Exporter
    {
        $config = config("ai-observability.exporters.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Exporter [{$name}] is not configured.");
        }

        $driver = $config['driver'] ?? null;

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException("Exporter [{$name}] does not define a driver.");
        }

        if (isset($this->extensions[$driver])) {
            return ($this->extensions[$driver])($this->app, $config, $name);
        }

        return match ($driver) {
            'null' => new NullExporter,
            'log' => $this->createLogExporter($config),
            'stack' => $this->createStackExporter($name, $config),
            default => throw new InvalidArgumentException("Exporter driver [{$driver}] is not supported."),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createLogExporter(array $config): LogExporter
    {
        $channel = $config['channel'] ?? null;
        $level = $config['level'] ?? 'debug';

        return new LogExporter(
            $this->app->make(LogManager::class),
            is_string($channel) && $channel !== '' ? $channel : null,
            is_string($level) && $level !== '' ? $level : 'debug',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createStackExporter(string $name, array $config): StackExporter
    {
        $channels = $config['channels'] ?? [];

        if (! is_array($channels)) {
            throw new InvalidArgumentException("Stack exporter [{$name}] channels must be an array.");
        }

        return new StackExporter(array_map(
            fn (string $channel): Exporter => $this->exporter($channel),
            $this->validateStackChannels($name, $channels),
        ));
    }

    /**
     * @param  array<mixed>  $channels
     * @return list<string>
     */
    private function validateStackChannels(string $name, array $channels): array
    {
        $validated = [];

        foreach ($channels as $index => $channel) {
            if (! is_string($channel) || $channel === '') {
                throw new InvalidArgumentException("Stack exporter [{$name}] channel at index [{$index}] must be a non-empty string.");
            }

            $validated[] = $channel;
        }

        return $validated;
    }
}
