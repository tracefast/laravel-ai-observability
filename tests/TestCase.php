<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Tracefast\LaravelAiObservability\LaravelAiObservabilityServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelAiObservabilityServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
