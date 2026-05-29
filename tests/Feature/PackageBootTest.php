<?php

declare(strict_types=1);

use Tracefast\LaravelAiObservability\AiObservability;
use Tracefast\LaravelAiObservability\Context\ObservationContext;
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
    expect(config('ai-observability.enabled'))->toBeTrue()
        ->and(config('ai-observability.default'))->toBe('log')
        ->and(config('ai-observability.capture.content'))->toBe('full')
        ->and(config('ai-observability.export.mode'))->toBe('defer')
        ->and(app(AiObservability::class))->toBeInstanceOf(AiObservability::class)
        ->and(app(ObservationContext::class))->toBeInstanceOf(ObservationContext::class);
});

it('scopes custom observation attributes to a callback', function (): void {
    $observability = app(AiObservability::class);

    $attributes = $observability->withSession(
        sessionId: 'conversation-123',
        callback: fn (): array => app(ObservationContext::class)->attributes(),
        userId: 42,
        attributes: [
            'app.conversation.uuid' => 'conversation-123',
            'null.value' => null,
        ],
    );

    expect($attributes)->toBe([
        'app.conversation.uuid' => 'conversation-123',
        'session.id' => 'conversation-123',
        'user.id' => '42',
    ])
        ->and(app(ObservationContext::class)->attributes())->toBe([]);
});

it('scopes the root observability service to the current container scope', function (): void {
    $observability = app(AiObservability::class);

    app()->forgetScopedInstances();

    expect(app(AiObservability::class))->not->toBe($observability);
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
