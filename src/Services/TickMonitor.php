<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Node;
use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\TickSeries;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * TPS and MSPT under the console (the widgets in Filament/Server/Widgets): whether a server gets them,
 * and the answer of GET /ticks, shared by both widgets.
 *
 * The console is the page every user opens first, so it must never wait for a bridge that isn't there.
 * Whether a node's bridge has the tick monitor (feature ticks) is asked once and kept for a few minutes,
 * also when no bridge answers. Only then is the server asked (GET /status, cached by ServerCapabilities).
 */
class TickMonitor
{
    /** Seconds the features of a node's bridge are kept. Saving the bridge address forgets them. */
    public const NODE_CACHE_SECONDS = 300;

    /** Seconds "no bridge answers" is kept: a bridge that is just restarting is asked again soon. */
    public const NODE_FAILED_CACHE_SECONDS = 30;

    /** Seconds an answer of GET /ticks is shared, both widgets poll every 5 s. */
    public const SHARE_SECONDS = 4;

    /** A JVM that runs this long and still shows no tick loop has none the bridge knows (an obfuscated version). */
    public const UNSUPPORTED_AFTER_MS = 600_000;

    /** Milliseconds the tick monitor must have looked before its "unsupported" counts, not a single window. */
    public const UNSUPPORTED_MONITOR_MS = 60_000;

    /** Seconds such a JVM is remembered, the widgets stay away for it the next time the console opens. */
    public const UNSUPPORTED_KEEP_SECONDS = 86_400;

    public function __construct(
        protected BridgeClient $bridge,
        protected ServerCapabilities $capabilities,
    ) {}

    /**
     * Whether the user may see the values of this server, checked on every update of the widgets. Proxies
     * have no Server thread and no ticks.
     */
    public static function mayAccess(Server $server): bool
    {
        return (user()?->canAccessTenant($server) ?? false)
            && !$server->isInConflictState()
            && MinecraftPerformanceAnalyserPlugin::isEnabledForServer($server)
            && !ServerCapabilities::isProxy($server)
            && Permissions::check(Permissions::READ, $server);
    }

    /**
     * Whether the console shows the widgets: the bridge of the node has the tick monitor, the server runs
     * Java the bridge may attach to (or will in a moment, the JVM is starting), and it wasn't found
     * without a tick loop before.
     */
    public function shows(Server $server): bool
    {
        if (!self::mayAccess($server) || !$this->nodeHasTicks($server->node)) {
            return false;
        }

        $capabilities = $this->capabilities->for($server);
        $reason = $capabilities->reason(Capabilities::TICKS);

        if ($reason !== null && $reason !== 'attach_jvm_starting') {
            return false;
        }

        $jvmStartedAt = $capabilities->jvmStartedAt();

        return $jvmStartedAt === null || Cache::get($this->unsupportedKey($server)) !== $jvmStartedAt;
    }

    public function nodeHasTicks(Node $node): bool
    {
        $features = Cache::get(self::nodeCacheKey($node));

        if (!is_array($features)) {
            $failed = false;

            try {
                $features = $this->bridge->nodeStatus($node)['features'] ?? [];
                $features = is_array($features) ? array_values(array_filter($features, 'is_string')) : [];
            } catch (BridgeException) {
                [$features, $failed] = [[], true];
            } catch (Throwable $exception) {
                report($exception);
                [$features, $failed] = [[], true];
            }

            Cache::put(self::nodeCacheKey($node), $features, $failed ? self::NODE_FAILED_CACHE_SECONDS : self::NODE_CACHE_SECONDS);
        }

        return in_array(Capabilities::FEATURES[Capabilities::TICKS], $features, true);
    }

    public static function nodeCacheKey(Node $node): string
    {
        return 'minecraft-performance-analyser:node-features:'.$node->id;
    }

    /**
     * GET /ticks of the server, or the translated message of what went wrong.
     *
     * @return array{ticks?: array<string, mixed>, error?: string}
     */
    public function ticks(Server $server): array
    {
        $answer = Cache::remember('minecraft-performance-analyser:ticks:'.$server->id, self::SHARE_SECONDS, function () use ($server) {
            try {
                return ['ticks' => $this->bridge->ticks($server)];
            } catch (BridgeException $exception) {
                return ['error' => $exception->getMessage()];
            } catch (Throwable $exception) {
                report($exception);

                return ['error' => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected')];
            }
        });

        if (!is_array($answer)) {
            return ['error' => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected')];
        }

        if (is_array($answer['ticks'] ?? null)) {
            $this->rememberSupport($server, $answer['ticks']);
        }

        return $answer;
    }

    /**
     * Remember a JVM that has run for a while without a tick loop the bridge knows, forget it once it has one.
     *
     * @param  array<string, mixed>  $ticks
     */
    protected function rememberSupport(Server $server, array $ticks): void
    {
        $key = $this->unsupportedKey($server);
        $state = $ticks['state'] ?? null;

        if ($state === 'unsupported') {
            $jvmStartedAt = $this->capabilities->for($server)->jvmStartedAt();
            // The clock of the node, like the start of the JVM.
            $now = is_int($ticks['sampled_at'] ?? null) ? $ticks['sampled_at'] : (int) floor(microtime(true) * 1000);

            if (TickSeries::unsupportedForGood($ticks, $jvmStartedAt, $now, self::UNSUPPORTED_AFTER_MS, self::UNSUPPORTED_MONITOR_MS)) {
                Cache::put($key, $jvmStartedAt, self::UNSUPPORTED_KEEP_SECONDS);
            }
        } elseif (in_array($state, ['ok', 'idle', 'paused'], true) && Cache::has($key)) {
            Cache::forget($key);
        }
    }

    protected function unsupportedKey(Server $server): string
    {
        return 'minecraft-performance-analyser:ticks-unsupported:'.$server->id;
    }
}
