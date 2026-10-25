<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Providers;

use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\PackageIndexService;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ProfileStore;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\SnapshotStore;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The stored profiles, histogram, thread dump and the package index of a server go with it.
 */
class ServerCleanupServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Server::deleted(function (Server $server) {
            try {
                app(ProfileStore::class)->deleteServer($server);
                app(SnapshotStore::class)->deleteServer($server);
                app(PackageIndexService::class)->forget($server);
            } catch (Throwable $exception) {
                // Never keep a server from being deleted.
                report($exception);
            }
        });
    }
}
