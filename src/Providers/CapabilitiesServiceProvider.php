<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Providers;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\ServerCapabilities;
use Illuminate\Support\ServiceProvider;

/**
 * One ServerCapabilities per request, so the page and its tabs share what the bridge said.
 */
class CapabilitiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ServerCapabilities::class);
    }
}
