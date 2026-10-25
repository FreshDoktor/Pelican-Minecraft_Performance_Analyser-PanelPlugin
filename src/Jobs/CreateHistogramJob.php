<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Jobs;

use App\Models\Server;
use App\Models\User;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\BridgeClient;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\SnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Asks the bridge for a class histogram (GET /histogram) and stores it, see the tab Memory.
 *
 * On the queue because the heap walk may take the bridge up to 90 s (its attach timeout), longer than a
 * web request of the panel may run (PHP max_execution_time, the proxy in front of PHP). The tab polls
 * the status (Memory::histogramStatus()) meanwhile.
 */
class CreateHistogramJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Seconds the panel waits for the bridge. The bridge allows the command 90 s, but a job running longer
     * than retry_after of the queue (90 s by default in Laravel, Pelican doesn't change it) would be handed
     * to a second worker, so the job stays below that. A heap walk taking that long is rare.
     */
    public const BRIDGE_TIMEOUT = 80;

    public const DEFAULT_LIMIT = 2000;

    public int $tries = 1;

    public int $timeout = 88;

    public function __construct(public int $serverId, public ?int $userId = null) {}

    public function handle(BridgeClient $bridge, SnapshotStore $store): void
    {
        $server = Server::find($this->serverId);

        if (!$server) {
            cache()->forget(self::statusKey($this->serverId));

            return;
        }

        self::putStatus($this->serverId, 'running');

        $limit = max(1, min(100000, (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.histogram_limit', self::DEFAULT_LIMIT)));

        try {
            $histogram = $bridge->get($server, 'histogram', ['limit' => $limit], self::BRIDGE_TIMEOUT);
            $store->put($server, SnapshotStore::HISTOGRAM, $histogram, $this->userId ? User::find($this->userId) : null);
            cache()->forget(self::statusKey($this->serverId));
        } catch (BridgeException $exception) {
            self::putStatus($this->serverId, 'failed', ['error' => $exception->errorCode, 'message' => $exception->getMessage(), 'details' => $exception->details]);
        } catch (Throwable $exception) {
            report($exception);
            self::putStatus($this->serverId, 'failed', ['error' => BridgeException::BRIDGE_ERROR, 'message' => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected'), 'details' => []]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        self::putStatus($this->serverId, 'failed', ['error' => BridgeException::BRIDGE_ERROR, 'message' => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected'), 'details' => []]);
    }

    public static function statusKey(int $serverId): string
    {
        return MinecraftPerformanceAnalyserPlugin::ID.':histogram-status:'.$serverId;
    }

    /**
     * @param  array{error: string, message: string, details: array<string, mixed>}|null  $error
     */
    public static function putStatus(int $serverId, string $status, ?array $error = null): void
    {
        $previous = cache()->get(self::statusKey($serverId));

        cache()->put(self::statusKey($serverId), [
            'status' => $status,
            'queued_at' => is_array($previous) ? ($previous['queued_at'] ?? time()) : time(),
            'error' => $error,
        ], now()->addMinutes(10));
    }

    /**
     * @return array{status: string, queued_at: int, error: array<string, mixed>|null}|null
     */
    public static function status(int $serverId): ?array
    {
        $status = cache()->get(self::statusKey($serverId));

        return is_array($status) ? $status : null;
    }
}
