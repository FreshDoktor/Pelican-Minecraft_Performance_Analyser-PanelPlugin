<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

/**
 * Turns a finished profile of the bridge (docs/API.md, "Profile result", wing commit a1b761f) into what
 * the tab Lag analysis shows: the share of the tick time per mod, the top methods, the GC pauses and
 * the stacks for the flamegraph.
 *
 * Only the thread "Server thread" counts for the share per mod, it runs the ticks. With the mode wall
 * it is sampled every interval whether it works or waits; samples below MinecraftServer.waitUntilNextTick
 * or MinecraftServer.waitForTasks are the idle time between two ticks, everything else is tick work.
 * The shares are of the tick work only. The methods are named after the mappings the server runs with
 * (MAPPINGS, the same table as in docs/API.md of the bridge, "Tick and idle methods").
 *
 * - Self time: the sample goes to the owner of the deepest frame that is not the JVM's (time in a
 *   HashMap called by a mod is the mod's). Waiting inside a tick (that frame is waitForTasks, e.g. a mod
 *   loads a chunk and waits for it) goes to the deepest mod or plugin in the stack, else stays Minecraft's.
 * - Inclusive time: every owner anywhere in the stack, once per sample.
 *
 * Plain PHP, tested without Laravel.
 *
 * @phpstan-import-type Owner from ModPackageIndex
 */
final class ProfileAnalyzer
{
    public const SERVER_THREAD = 'Server thread';

    public const MAPPING_MOJMAP = 'mojmap';

    public const MAPPING_INTERMEDIARY = 'intermediary';

    public const MAPPING_SRG = 'srg';

    public const MAPPING_MCP = 'mcp';

    /**
     * Methods of MinecraftServer (or the BlockableEventLoop it extends) by the mappings the server runs
     * with, checked against Mojang's server mappings, Fabric's intermediary and MCPConfig (2026-10-25):
     *
     * - tick: tickServer and tickChildren. Waiting below one of them (e.g. for a chunk to load) is lag,
     *   not idle time.
     * - idle: waitUntilNextTick and waitForTasks, the Server thread waits there between two ticks.
     *
     * mojmap: vanilla from 26.1, Paper from 1.20.5, NeoForge from 1.20.2, Forge from 1.20.6.
     * intermediary: Fabric and Quilt, the same names in every version. srg: Forge 1.17 to 1.20 and
     * NeoForge 1.20.1. mcp: Forge 1.13 to 1.16 (the ids of MCP stay the same across versions).
     */
    public const MAPPINGS = [
        self::MAPPING_MOJMAP => [
            'tick' => ['tickServer', 'tickChildren'],
            'idle' => ['waitUntilNextTick', 'waitForTasks'],
        ],
        self::MAPPING_INTERMEDIARY => [
            'tick' => ['method_3748', 'method_3813'],
            'idle' => ['method_16208', 'method_20813'],
        ],
        self::MAPPING_SRG => [
            'tick' => ['m_5705_', 'm_5703_'],
            'idle' => ['m_130012_', 'm_5667_'],
        ],
        self::MAPPING_MCP => [
            'tick' => ['func_71217_p', 'func_71190_q'],
            'idle' => ['func_213202_o', 'func_223705_bi'],
        ],
    ];

    /**
     * Up to 1.13 there is no waitUntilNextTick: MinecraftServer.run (Runnable.run, the same name in every
     * mapping) calls Thread.sleep itself between two ticks. Counts as mcp, the tick methods are the same.
     */
    public const LEGACY_IDLE = ['run', 'java/lang/Thread.sleep'];

    /**
     * runServer per mapping, the loop that calls the tick. A stack in it that blocks (BLOCKING) with only
     * mod, JDK or mixin frames ($ in the method) in between is paused by a mod between two ticks, e.g. Tick
     * Stasis parks the Server thread while nobody is online: idle. Minecraft's own code in between is work
     * (loading the worlds at start).
     */
    public const RUN_LOOP = [
        self::MAPPING_MOJMAP => 'runServer',
        self::MAPPING_INTERMEDIARY => 'method_29741',
        self::MAPPING_SRG => 'm_130011_',
        self::MAPPING_MCP => 'func_240802_v_',
    ];

    /** Frames of a thread that blocks (park, sleep, wait), as prefixes. */
    public const BLOCKING = [
        'java/util/concurrent/locks/LockSupport.park',
        'jdk/internal/misc/Unsafe.park',
        'sun/misc/Unsafe.park',
        'java/lang/Thread.sleep',
        'java/lang/Object.wait',
    ];

    public const TOP_METHODS = 30;

    public const STATE_OK = 'ok';

    /** The bridge recorded nothing (warning no_samples), typical for cpu mode on a paused server. */
    public const STATE_NO_SAMPLES = 'no_samples';

    /** No thread named "Server thread" (a proxy, or a server that names it differently). */
    public const STATE_NO_SERVER_THREAD = 'no_server_thread';

    /** The Server thread only waited: paused (pause-when-empty-seconds, nobody online) or without any load. */
    public const STATE_IDLE = 'idle';

    /**
     * @param  array<string, mixed>  $profile  profile object of the bridge, state done
     * @return array<string, mixed>
     */
    public function analyze(array $profile, ModPackageIndex $index): array
    {
        $result = is_array($profile['result'] ?? null) ? $profile['result'] : [];
        $interval = (float) ($result['interval_ms'] ?? $profile['interval_ms'] ?? 0);
        $unit = (string) ($result['unit'] ?? 'samples');
        $warnings = array_values(array_filter($result['warnings'] ?? [], 'is_string'));

        $serverThread = null;
        $otherThreads = [];

        foreach ($result['threads'] ?? [] as $thread) {
            if (!is_array($thread)) {
                continue;
            }

            if ($serverThread === null && ($thread['name'] ?? null) === self::SERVER_THREAD) {
                $serverThread = $thread;
            } else {
                $otherThreads[] = ['name' => (string) ($thread['name'] ?? ''), 'count' => (int) ($thread['count'] ?? 0)];
            }
        }

        $analysis = [
            'id' => (string) ($profile['id'] ?? ''),
            'mode' => (string) ($profile['mode'] ?? ''),
            'seconds' => (int) ($profile['seconds'] ?? 0),
            'started_at' => (int) ($profile['started_at'] ?? 0),
            'duration_ms' => (float) ($result['duration_ms'] ?? 0),
            'interval_ms' => $interval,
            'unit' => $unit,
            'warnings' => $warnings,
            'state' => self::STATE_OK,
            'server_thread' => null,
            'owners' => [],
            'top_methods' => [],
            'other_threads' => array_slice($otherThreads, 0, 10),
            'gc' => $this->gc($result['gc'] ?? []),
            'safepoints' => [
                'count' => (int) ($result['safepoints']['count'] ?? 0),
                'time_ms' => (float) ($result['safepoints']['time_ms'] ?? 0),
            ],
            'cpu_percent' => isset($result['cpu']['process_percent']) ? (float) $result['cpu']['process_percent'] : null,
            'flame' => ['owners' => [], 'frames' => [], 'stacks' => []],
        ];

        if (in_array('no_samples', $warnings, true) || (int) ($result['total'] ?? 0) === 0) {
            $analysis['state'] = self::STATE_NO_SAMPLES;

            return $analysis;
        }

        if ($serverThread === null) {
            $analysis['state'] = self::STATE_NO_SERVER_THREAD;

            return $analysis;
        }

        return $this->analyzeServerThread($analysis, $serverThread, $index, $interval, $unit);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $thread
     * @return array<string, mixed>
     */
    protected function analyzeServerThread(array $analysis, array $thread, ModPackageIndex $index, float $interval, string $unit): array
    {
        $samples = 0;
        $idle = 0;

        /** @var array<string, int> $mappings samples with a tick or idle frame, by mapping */
        $mappings = [];

        /** @var array<string, int> $self */
        $self = [];
        /** @var array<string, int> $inclusive */
        $inclusive = [];
        /** @var array<string, int> $methodSelf */
        $methodSelf = [];
        /** @var array<string, int> $methodInclusive */
        $methodInclusive = [];

        $ownerCache = [];
        $ownerOf = function (string $frame) use (&$ownerCache, $index): string {
            return $ownerCache[$frame] ??= $index->ownerOf($frame);
        };

        // Frame table for the flamegraph: every frame once, with its owner.
        $frameIds = [];
        $frames = [];
        $ownerIds = [];
        $flameOwners = [];
        $flameStacks = [];

        foreach ($thread['stacks'] ?? [] as $entry) {
            $count = (int) ($entry['count'] ?? 0);
            $stack = is_string($entry['stack'] ?? null) ? $entry['stack'] : '';

            if ($count <= 0 || $stack === '') {
                continue;
            }

            $stackFrames = explode(';', $stack);
            $samples += $count;

            [$kind, $mapping] = $this->classify($stackFrames);
            $isIdle = $unit === 'samples' && $kind === 'idle';

            if ($mapping !== null) {
                $mappings[$mapping] = ($mappings[$mapping] ?? 0) + $count;
            }

            $ids = [];
            foreach ($stackFrames as $frame) {
                if (!isset($frameIds[$frame])) {
                    $owner = $ownerOf($frame);

                    if (!isset($ownerIds[$owner])) {
                        $ownerIds[$owner] = count($flameOwners);
                        $flameOwners[] = $index->owner($owner);
                    }

                    $frameIds[$frame] = count($frames);
                    $frames[] = [$frame, $ownerIds[$owner]];
                }

                $ids[] = $frameIds[$frame];
            }

            $flameStacks[] = [$ids, $count, $isIdle];

            if ($isIdle) {
                $idle += $count;

                continue;
            }

            // Self: the deepest frame that is not the JVM's.
            $selfFrame = null;
            $selfOwner = ModPackageIndex::JVM;

            for ($i = count($stackFrames) - 1; $i >= 0; $i--) {
                $owner = $ownerOf($stackFrames[$i]);

                if ($owner !== ModPackageIndex::JVM) {
                    $selfFrame = $stackFrames[$i];
                    $selfOwner = $owner;

                    break;
                }
            }

            // Waiting inside a tick (e.g. for a chunk): the mod that made the Server thread wait.
            if ($kind === 'tick' && $selfOwner === ModPackageIndex::MINECRAFT && in_array(ModPackageIndex::split($selfFrame)[1], self::MAPPINGS[$mapping]['idle'], true)) {
                for ($i--; $i >= 0; $i--) {
                    $owner = $ownerOf($stackFrames[$i]);

                    if (in_array($index->owner($owner)['kind'], [ModPackageIndex::KIND_MOD, ModPackageIndex::KIND_PLUGIN], true)) {
                        $selfFrame = $stackFrames[$i];
                        $selfOwner = $owner;

                        break;
                    }
                }
            }

            $selfFrame ??= $stackFrames[count($stackFrames) - 1];

            $self[$selfOwner] = ($self[$selfOwner] ?? 0) + $count;
            $methodSelf[$selfFrame] = ($methodSelf[$selfFrame] ?? 0) + $count;

            $seenOwners = [];
            $seenFrames = [];

            foreach ($stackFrames as $frame) {
                $seenOwners[$ownerOf($frame)] = true;
                $seenFrames[$frame] = true;
            }

            foreach (array_keys($seenOwners) as $owner) {
                $inclusive[$owner] = ($inclusive[$owner] ?? 0) + $count;
            }

            foreach (array_keys($seenFrames) as $frame) {
                $methodInclusive[$frame] = ($methodInclusive[$frame] ?? 0) + $count;
            }
        }

        $work = $samples - $idle;
        $toMs = fn (int $count) => $unit === 'samples' ? round($count * $interval, 3) : null;
        $percent = fn (int $count) => $work > 0 ? round($count / $work * 100, 2) : 0.0;

        $owners = [];

        foreach ($inclusive as $key => $count) {
            $owner = $index->owner($key);
            $owners[] = [
                ...$owner,
                'self' => $self[$key] ?? 0,
                'inclusive' => $count,
                'self_percent' => $percent($self[$key] ?? 0),
                'inclusive_percent' => $percent($count),
                'self_ms' => $toMs($self[$key] ?? 0),
                'inclusive_ms' => $toMs($count),
            ];
        }

        usort($owners, fn (array $a, array $b) => [$b['self'], $b['inclusive'], $a['name']] <=> [$a['self'], $a['inclusive'], $b['name']]);

        arsort($methodSelf);
        $topMethods = [];

        foreach (array_slice($methodSelf, 0, self::TOP_METHODS, true) as $frame => $count) {
            $owner = $index->owner($ownerOf((string) $frame));
            $topMethods[] = [
                'frame' => (string) $frame,
                'owner' => $owner['key'],
                'owner_name' => $owner['name'],
                'self' => $count,
                'self_percent' => $percent($count),
                'inclusive' => $methodInclusive[$frame] ?? $count,
                'inclusive_percent' => $percent($methodInclusive[$frame] ?? $count),
            ];
        }

        arsort($mappings);
        $mapping = array_key_first($mappings);

        $analysis['server_thread'] = [
            'tid' => isset($thread['tid']) ? (int) $thread['tid'] : null,
            // Null when no frame matches MAPPINGS (e.g. an obfuscated vanilla or Spigot server): the idle
            // time can't be told apart then.
            'mapping' => $mapping,
            'samples' => $samples,
            'idle' => $idle,
            'work' => $work,
            'idle_ms' => $toMs($idle),
            'work_ms' => $toMs($work),
            // Share of the time the Server thread worked on ticks: 100 % means it can't keep 20 TPS.
            'tick_load_percent' => $samples > 0 && $unit === 'samples' && $analysis['mode'] === 'wall' && $mapping !== null ? round($work / $samples * 100, 2) : null,
        ];
        $analysis['owners'] = $owners;
        $analysis['top_methods'] = $topMethods;
        $analysis['flame'] = ['owners' => $flameOwners, 'frames' => $frames, 'stacks' => $flameStacks];

        if ($work === 0) {
            $analysis['state'] = self::STATE_IDLE;
        }

        return $analysis;
    }

    /**
     * Whether a stack (root first) waits between two ticks: it has an idle frame of MinecraftServer,
     * and no tick frame above it (waiting inside a tick, e.g. for a chunk, is lag).
     *
     * @param  list<string>  $frames
     */
    public function isIdle(array $frames): bool
    {
        return $this->classify($frames)[0] === 'idle';
    }

    /**
     * The first tick or idle frame of MinecraftServer in a stack (root first), and the mapping it is named
     * in. Without one, a stack paused in runServer (RUN_LOOP) is idle. [null, null] when nothing decides,
     * e.g. a stack the profiler couldn't walk ([unknown_Java]).
     *
     * @param  list<string>  $frames
     * @return array{0: 'tick'|'idle'|null, 1: string|null}
     */
    public function classify(array $frames): array
    {
        // Mapping of runServer while only mod, JDK or mixin frames followed it.
        $run = null;
        $ranOut = false;

        foreach ($frames as $i => $frame) {
            [$class, $method] = ModPackageIndex::split($frame);

            if ($class === null || $method === null || !str_ends_with($class, '/MinecraftServer')) {
                if ($run !== null && $this->blocks($frame)) {
                    return ['idle', $run];
                }

                if (str_starts_with($frame, 'net/minecraft/')) {
                    $run = null;
                }

                continue;
            }

            if ($method === self::LEGACY_IDLE[0] && ($frames[$i + 1] ?? null) === self::LEGACY_IDLE[1]) {
                return ['idle', self::MAPPING_MCP];
            }

            foreach (self::MAPPINGS as $mapping => $methods) {
                foreach (['tick', 'idle'] as $kind) {
                    if (in_array($method, $methods[$kind], true)) {
                        return [$kind, $mapping];
                    }
                }
            }

            $loop = $ranOut ? false : array_search($method, self::RUN_LOOP, true);

            if ($loop !== false) {
                [$run, $ranOut] = [$loop, true];
            } elseif (!str_contains($method, '$')) {
                $run = null;
            }
        }

        return [null, null];
    }

    protected function blocks(string $frame): bool
    {
        foreach (self::BLOCKING as $prefix) {
            if (str_starts_with($frame, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $gc
     * @return array{count: int, time_ms: float, pauses: list<array{kind: string, start_offset_ms: float, duration_ms: float}>, pauses_complete: bool}
     */
    protected function gc(mixed $gc): array
    {
        $gc = is_array($gc) ? $gc : [];
        $pauses = [];

        foreach ($gc['pauses'] ?? [] as $pause) {
            if (is_array($pause)) {
                $pauses[] = [
                    'kind' => (string) ($pause['kind'] ?? 'unknown'),
                    'start_offset_ms' => (float) ($pause['start_offset_ms'] ?? 0),
                    'duration_ms' => (float) ($pause['duration_ms'] ?? 0),
                ];
            }
        }

        return [
            'count' => (int) ($gc['count'] ?? 0),
            'time_ms' => (float) ($gc['time_ms'] ?? 0),
            'pauses' => $pauses,
            'pauses_complete' => (bool) ($gc['pauses_complete'] ?? true),
        ];
    }
}
