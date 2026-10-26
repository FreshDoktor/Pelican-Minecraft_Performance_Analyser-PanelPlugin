<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Jobs;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\MethodNameService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Downloads the mappings of a Minecraft version and keeps the readable method names (see
 * MethodNameService). On the queue because Mojang's server mappings alone have about 8 MB; the tab Lag
 * analysis queues it when a profile needs the names and picks them up once they are there.
 */
class BuildMethodNamesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $mapping, public string $version) {}

    public function handle(MethodNameService $service): void
    {
        try {
            $service->updateStatus($this->mapping, $this->version, 'running');
            $service->build($this->mapping, $this->version);
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $service->finish($this->mapping, $this->version);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(MethodNameService::class)->finish($this->mapping, $this->version);
    }
}
