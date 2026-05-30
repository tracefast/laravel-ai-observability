<?php

declare(strict_types=1);

namespace Tracefast\LaravelAiObservability\Support;

use Composer\InstalledVersions;
use Throwable;

final class PackageInfo
{
    public const Name = 'tracefast/laravel-ai-observability';

    public const LaravelAiName = 'laravel/ai';

    public const OpenInferenceSchemaVersion = '1.0.0';

    public static function packageVersion(): string
    {
        return self::version(self::Name);
    }

    public static function laravelAiVersion(): string
    {
        return self::version(self::LaravelAiName);
    }

    private static function version(string $package): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            return InstalledVersions::getPrettyVersion($package)
                ?? InstalledVersions::getVersion($package)
                ?? 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
