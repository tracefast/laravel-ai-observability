<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Tracefast\LaravelAiObservability\AiObservability;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\DatabaseExporter;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;
use Tracefast\LaravelAiObservability\Exporters\NullExporter;
use Tracefast\LaravelAiObservability\Exporters\StackExporter;

it('resolves the configured default exporter', function (): void {
    config()->set('ai-observability.default', 'null');

    expect(app(ExporterManager::class)->exporter())->toBeInstanceOf(NullExporter::class)
        ->and(app(AiObservability::class)->exporter())->toBeInstanceOf(NullExporter::class);
});

it('resolves stack exporters from configured channels', function (): void {
    config()->set('ai-observability.exporters.stack.channels', ['null']);

    expect(app(ExporterManager::class)->exporter('stack'))->toBeInstanceOf(StackExporter::class);
});

it('resolves database exporters', function (): void {
    expect(app(ExporterManager::class)->exporter('database'))->toBeInstanceOf(DatabaseExporter::class);
});

it('rejects direct stack cycles', function (): void {
    config()->set('ai-observability.exporters.stack.channels', ['stack']);

    expect(fn () => app(ExporterManager::class)->exporter('stack'))
        ->toThrow(InvalidArgumentException::class, 'Circular exporter stack detected while resolving [stack].');
});

it('rejects indirect stack cycles', function (): void {
    config()->set('ai-observability.exporters.stack.channels', ['secondary']);
    config()->set('ai-observability.exporters.secondary', [
        'driver' => 'stack',
        'channels' => ['stack'],
    ]);

    expect(fn () => app(ExporterManager::class)->exporter('stack'))
        ->toThrow(InvalidArgumentException::class, 'Circular exporter stack detected while resolving [stack].');
});

it('rejects non-string stack channels', function (): void {
    config()->set('ai-observability.exporters.stack.channels', ['null', 123]);

    expect(fn () => app(ExporterManager::class)->exporter('stack'))
        ->toThrow(InvalidArgumentException::class, 'Stack exporter [stack] channel at index [1] must be a non-empty string.');
});

it('rejects empty string stack channels', function (): void {
    config()->set('ai-observability.exporters.stack.channels', ['']);

    expect(fn () => app(ExporterManager::class)->exporter('stack'))
        ->toThrow(InvalidArgumentException::class, 'Stack exporter [stack] channel at index [0] must be a non-empty string.');
});

it('supports custom driver extensions', function (): void {
    config()->set('ai-observability.exporters.memory', [
        'driver' => 'memory',
        'label' => 'custom',
    ]);

    $exporter = new class implements Exporter
    {
        public function export(Trace $trace): void
        {
            //
        }
    };

    app(AiObservability::class)->extend(
        'memory',
        function (Application $app, array $config, string $name) use ($exporter): Exporter {
            expect($app)->toBe(app())
                ->and($config['label'])->toBe('custom')
                ->and($name)->toBe('memory');

            return $exporter;
        },
    );

    expect(app(AiObservability::class)->exporter('memory'))->toBe($exporter);
});
