<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The Capabilities of a server for the page and its tabs: GET /status of the bridge (costs the JVM
 * nothing), whether the egg is a proxy, and the newest stored profile.
 *
 * The answer is kept for a few seconds in the cache, so the page and every tab ask the bridge once
 * between them, and once more per request (bound scoped, see CapabilitiesServiceProvider).
 */
class ServerCapabilities
{
    /** Seconds the answer of /status is kept. */
    public const CACHE_SECONDS = 10;

    /** Tag of Pelican's proxy eggs (BungeeCord, Velocity, Waterfall): they have no Server thread. */
    public const PROXY_TAG = 'proxy';

    /** @var array<int, Capabilities> by server id */
    protected array $resolved = [];

    public function __construct(
        protected BridgeClient $bridge,
        protected ProfileStore $profiles,
        protected SnapshotStore $snapshots,
    ) {}

    public function for(Server $server): Capabilities
    {
        return $this->resolved[$server->id] ??= $this->resolve($server);
    }

    /**
     * Forget what is known, e.g. after the bridge refused an action the capabilities allowed.
     */
    public function forget(Server $server): void
    {
        unset($this->resolved[$server->id]);
        Cache::forget($this->cacheKey($server));
    }

    /**
     * Whether a tab is shown: its action can be done, or may be done once the server runs, or there is a
     * stored result to look at.
     */
    public function showsTab(Server $server, string $action): bool
    {
        return !$this->for($server)->hidden($action) || $this->hasStored($server, $action);
    }

    public function hasStored(Server $server, string $action): bool
    {
        try {
            return match ($action) {
                Capabilities::PROFILE => $this->profiles->latestKey($server) !== null,
                Capabilities::HISTOGRAM => $this->snapshots->has($server, SnapshotStore::HISTOGRAM),
                Capabilities::THREADS => $this->snapshots->has($server, SnapshotStore::THREADS),
                default => false,
            };
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public static function isProxy(Server $server): bool
    {
        $server->loadMissing('egg');

        return in_array(self::PROXY_TAG, $server->egg->tags ?? [], true);
    }

    protected function resolve(Server $server): Capabilities
    {
        // Only arrays go into the cache.
        $cached = Cache::remember($this->cacheKey($server), self::CACHE_SECONDS, function () use ($server) {
            try {
                return ['status' => $this->bridge->status($server), 'profile' => $this->latestProfile($server)];
            } catch (BridgeException $exception) {
                return ['missing' => $exception->isBridgeMissing()];
            } catch (Throwable $exception) {
                report($exception);

                return ['missing' => false];
            }
        });

        if (!is_array($cached['status'] ?? null)) {
            return Capabilities::bridgeFailed((bool) ($cached['missing'] ?? false));
        }

        return Capabilities::fromStatus($cached['status'], self::isProxy($server), is_array($cached['profile'] ?? null) ? $cached['profile'] : null);
    }

    /**
     * What Capabilities needs of the newest stored profile: when it started and its warnings. Profiles can be
     * megabytes, the cache only keeps this.
     *
     * @return array{started_at: int, result: array{warnings: list<string>}}|null
     */
    protected function latestProfile(Server $server): ?array
    {
        try {
            $key = $this->profiles->latestKey($server);
            $profile = $key === null ? null : ($this->profiles->get($server, $key)['profile'] ?? null);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (!is_array($profile)) {
            return null;
        }

        $warnings = $profile['result']['warnings'] ?? [];

        return [
            'started_at' => (int) ($profile['started_at'] ?? 0),
            'result' => ['warnings' => is_array($warnings) ? array_values(array_filter($warnings, 'is_string')) : []],
        ];
    }

    protected function cacheKey(Server $server): string
    {
        return 'minecraft-performance-analyser:capabilities:'.$server->id;
    }
}
