<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\AiObservability;
use Tracefast\LaravelAiObservability\Contracts\Exporter;
use Tracefast\LaravelAiObservability\Data\Trace;

final class PackageBootCapturingExporter implements Exporter
{
    /**
     * @var list<Trace>
     */
    public array $traces = [];

    public function export(Trace $trace): void
    {
        $this->traces[] = $trace;
    }
}

it('registers the package config and root service', function (): void {
    expect(config('ai-observability.default'))->toBe('stack')
        ->and(config('ai-observability.capture.content'))->toBe('full')
        ->and(config('ai-observability.export.mode'))->toBe('defer')
        ->and(app(AiObservability::class))->toBeInstanceOf(AiObservability::class);
});

it('exports traces through the configured export mode', function (string $mode): void {
    config()->set('ai-observability.default', 'capturing');
    config()->set('ai-observability.export.mode', $mode);
    config()->set('ai-observability.exporters.capturing', ['driver' => 'capturing']);

    $exporter = new PackageBootCapturingExporter;
    app('ai-observability')->extend('capturing', fn (): PackageBootCapturingExporter => $exporter);

    app(AiObservability::class)->export(new Trace(traceId: '4bf92f3577b34da6a3ce929d0e0e4736', name: 'Agent'));

    expect($exporter->traces)->toHaveCount(1);
})->with(['defer', 'sync']);
