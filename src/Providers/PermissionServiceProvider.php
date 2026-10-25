<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Providers;

use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use Illuminate\Support\ServiceProvider;

/**
 * The subuser permissions of the plugin have to be known on every request that checks a permission of a
 * server (pages, Livewire updates, the client API), not only where the plugin's page is, so they are
 * registered here instead of in the plugin class, which only runs for the server panel.
 */
class PermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Permissions::register();
    }
}
