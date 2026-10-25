<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Jobs;

use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\PackageIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Reads the jars of mods/ and plugins/ of a server for the package index (see PackageIndexService):
 * which packages each one has classes in, and its name.
 *
 * Runs on the queue because it fetches every new jar through the daemon, which takes far too long for a
 * page load. The tab Lag analysis queues it and picks up the index once it is there.
 */
class BuildPackageIndexJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $serverId) {}

    public function handle(PackageIndexService $service): void
    {
        $server = Server::find($this->serverId);

        if (!$server) {
            $service->finish($this->serverId);

            return;
        }

        try {
            $service->updateStatus($server, 'running');

            $service->build($server, fn (int $current, int $total) => $service->updateStatus($server, 'running', $current, $total));
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $service->finish($server);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(PackageIndexService::class)->finish($this->serverId);
    }
}
