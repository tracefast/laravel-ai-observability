<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;
use Tracefast\LaravelAiObservability\Exporters\LogExporter;
use Tracefast\LaravelAiObservability\Exporters\NullExporter;
use Tracefast\LaravelAiObservability\Exporters\StackExporter;

function exporterTrace(): Trace
{
    return new Trace(
        traceId: 'trace_123',
        name: 'chat completion',
        startedAt: '2026-01-01T00:00:00.000000Z',
    );
}

it('drops traces with the null exporter', function (): void {
    $exporter = new NullExporter;

    $exporter->export(exporterTrace());

    expect(true)->toBeTrue();
});

it('writes structured traces to the configured log channel and level', function (): void {
    $logger = Mockery::mock(LoggerInterface::class);
    $logger
        ->shouldReceive('log')
        ->once()
        ->with('info', 'ai-observability.trace.exported', [
            'trace' => exporterTrace()->toArray(),
        ]);

    $log = Mockery::mock(LogManager::class);
    $log
        ->shouldReceive('channel')
        ->once()
        ->with('observability')
        ->andReturn($logger);

    (new LogExporter($log, 'observability', 'info'))->export(exporterTrace());
});

it('continues exporting a stack after one exporter fails', function (): void {
    $trace = exporterTrace();
    $calls = [];

    $failingExporter = new class implements Exporter
    {
        public function export(Trace $trace): void
        {
            throw new RuntimeException('Exporter failed.');
        }
    };

    $successfulExporter = new class($calls) implements Exporter
    {
        /**
         * @param  list<string>  $calls
         */
        public function __construct(
            private array &$calls,
        ) {}

        public function export(Trace $trace): void
        {
            $this->calls[] = $trace->toArray()['trace_id'];
        }
    };

    (new StackExporter([$failingExporter, $successfulExporter]))->export($trace);

    expect($calls)->toBe(['trace_123']);
});
