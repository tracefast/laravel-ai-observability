<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
        $this->app->singleton(AiObservability::class, fn (): AiObservability => new AiObservability);
        $this->app->alias(AiObservability::class, 'ai-observability');
    }
}
