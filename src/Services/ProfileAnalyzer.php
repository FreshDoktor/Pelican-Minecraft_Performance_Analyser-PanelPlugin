<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

/**
 * Turns a finished profile of the bridge (docs/API.md, "Profile result", wing commit 49f32b3) into what
 * the tab Lag analysis shows: the share of the tick time per mod, the top methods, the GC pauses and
 * the stacks for the flamegraph.
 *
 * Only the thread "Server thread" counts for the share per mod, it runs the ticks. With the mode wall
 * it is sampled every interval whether it works or waits; samples below MinecraftServer.waitUntilNextTick
 * or MinecraftServer.waitForTasks are the idle time between two ticks, everything else is tick work.
 * The shares are of the tick work only.
 *
 * - Self time: the sample goes to the owner of the deepest frame that is not the JVM's (time in a
 *   HashMap called by a mod is the mod's).
 * - Inclusive time: every owner anywhere in the stack, once per sample.
 *
 * Plain PHP, tested without Laravel.
 *
 * @phpstan-import-type Owner from ModPackageIndex
 */
final class ProfileAnalyzer
{
    public const SERVER_THREAD = 'Server thread';

    /** Frames the Server thread waits in between two ticks. */
    public const IDLE_METHODS = ['waitUntilNextTick', 'waitForTasks'];

    /**
     * Tick frames: waiting below one of them (e.g. for a chunk to load) is lag, not idle time.
     */
    public const TICK_METHODS = ['tickServer', 'tickChildren'];

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

            $isIdle = $unit === 'samples' && $this->isIdle($stackFrames);

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

        $analysis['server_thread'] = [
            'tid' => isset($thread['tid']) ? (int) $thread['tid'] : null,
            'samples' => $samples,
            'idle' => $idle,
            'work' => $work,
            'idle_ms' => $toMs($idle),
            'work_ms' => $toMs($work),
            // Share of the time the Server thread worked on ticks: 100 % means it can't keep 20 TPS.
            'tick_load_percent' => $samples > 0 && $unit === 'samples' && $analysis['mode'] === 'wall' ? round($work / $samples * 100, 2) : null,
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
        foreach ($frames as $frame) {
            [$class, $method] = ModPackageIndex::split($frame);

            if ($class === null || $method === null || !str_ends_with($class, '/MinecraftServer')) {
                continue;
            }

            if (in_array($method, self::TICK_METHODS, true)) {
                return false;
            }

            if (in_array($method, self::IDLE_METHODS, true)) {
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
