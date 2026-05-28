<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Illuminate\Contracts\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Tracefast\LaravelAiObservability\Exporters\ExporterManager;

class LaravelAiObservabilityServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ai-observability')
            ->hasConfigFile()
            ->hasMigration('create_ai_observability_tables');
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ExporterManager::class, fn (Application $app): ExporterManager => new ExporterManager($app));
        $this->app->singleton(AiObservability::class, fn (Application $app): AiObservability => new AiObservability(
            $app->make(ExporterManager::class),
        ));
        $this->app->alias(AiObservability::class, 'ai-observability');
    }
}
