<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

/**
 * Prepares a thread dump of the bridge (GET /threads, docs/API.md at wing commit 20f2692) for the tab
 * Threads: the threads by CPU time with their state (the State line, never the header), the mod a
 * thread works for (its deepest frame outside the JVM), which thread holds the lock another one waits
 * for, and the deadlocks.
 *
 * Plain PHP, tested without Laravel.
 */
final class ThreadDumpAnalyzer
{
    /** States of java.lang.Thread.State, in the order of the filter. */
    public const STATES = ['RUNNABLE', 'BLOCKED', 'WAITING', 'TIMED_WAITING', 'NEW', 'TERMINATED'];

    /** Filter value of the VM-internal threads, which have no state. */
    public const VM = 'VM';

    /** Lock types a thread waits on, see "Lock object" in docs/API.md. */
    protected const WAITING_LOCKS = ['waiting_to_lock', 'waiting_to_relock', 'parking', 'waiting_on'];

    /**
     * @param  array<string, mixed>  $dump  answer of GET /threads
     * @return array<string, mixed>
     */
    public function analyze(array $dump, ModPackageIndex $index): array
    {
        $threads = array_values(array_filter($dump['threads'] ?? [], 'is_array'));

        // Who holds which lock: locked monitors and owned synchronizers.
        $holders = [];
        foreach ($threads as $thread) {
            foreach ($thread['stack'] ?? [] as $frame) {
                foreach ($frame['locks'] ?? [] as $lock) {
                    if (($lock['type'] ?? null) === 'locked' && is_string($lock['address'] ?? null)) {
                        $holders[$lock['address']] ??= (string) ($thread['name'] ?? '');
                    }
                }
            }

            foreach ($thread['locked_synchronizers'] ?? [] as $lock) {
                if (is_string($lock['address'] ?? null)) {
                    $holders[$lock['address']] ??= (string) ($thread['name'] ?? '');
                }
            }
        }

        $deadlocked = [];
        foreach ($dump['deadlocks'] ?? [] as $deadlock) {
            foreach ($deadlock['threads'] ?? [] as $thread) {
                if (is_string($thread['name'] ?? null)) {
                    $deadlocked[$thread['name']] = true;
                }
            }
        }

        $states = array_fill_keys([...self::STATES, self::VM], 0);
        $rows = [];

        foreach ($threads as $thread) {
            $name = (string) ($thread['name'] ?? '');
            $vmThread = (bool) ($thread['vm_thread'] ?? false);
            $state = $vmThread ? self::VM : (is_string($thread['state'] ?? null) ? $thread['state'] : self::VM);
            $states[$state] = ($states[$state] ?? 0) + 1;

            $cpu = isset($thread['cpu_ms']) ? (float) $thread['cpu_ms'] : null;
            $elapsed = isset($thread['elapsed_ms']) ? (float) $thread['elapsed_ms'] : null;

            [$waitingFor, $heldBy] = $this->waitingFor($thread, $holders, $name);

            $rows[] = [
                'name' => $name,
                'java_id' => isset($thread['java_id']) ? (int) $thread['java_id'] : null,
                'os_id' => isset($thread['os_id']) ? (int) $thread['os_id'] : null,
                'daemon' => (bool) ($thread['daemon'] ?? false),
                'vm_thread' => $vmThread,
                'state' => $state,
                'state_detail' => is_string($thread['state_detail'] ?? null) ? $thread['state_detail'] : null,
                'cpu_ms' => $cpu,
                'elapsed_ms' => $elapsed,
                // Share of its lifetime the thread was on a CPU.
                'cpu_percent' => $cpu !== null && $elapsed !== null && $elapsed > 0 ? round($cpu / $elapsed * 100, 2) : null,
                'owner' => $this->owner($thread, $index),
                'top' => is_string($thread['stack'][0]['method'] ?? null) ? $thread['stack'][0]['method'] : null,
                'stack' => $this->stackLines($thread),
                'waiting_for' => $waitingFor,
                'held_by' => $heldBy,
                'deadlocked' => isset($deadlocked[$name]),
            ];
        }

        // The busiest first, threads without CPU time (none printed) at the end.
        usort($rows, fn (array $a, array $b) => [$b['deadlocked'], $b['cpu_ms'] ?? -1.0] <=> [$a['deadlocked'], $a['cpu_ms'] ?? -1.0]);

        return [
            'sampled_at' => (int) ($dump['sampled_at'] ?? 0),
            'duration_ms' => (float) ($dump['duration_ms'] ?? 0),
            'dumped_at_local' => is_string($dump['dumped_at_local'] ?? null) ? $dump['dumped_at_local'] : null,
            'vm' => is_string($dump['vm'] ?? null) ? $dump['vm'] : null,
            'total' => count($rows),
            'daemon' => (int) ($dump['summary']['daemon'] ?? count(array_filter($rows, fn (array $row) => $row['daemon']))),
            'states' => $states,
            'threads' => $rows,
            'deadlocks' => array_values(array_filter($dump['deadlocks'] ?? [], 'is_array')),
            'jni_refs' => is_array($dump['jni_refs'] ?? null) ? $dump['jni_refs'] : null,
            'raw' => is_string($dump['raw'] ?? null) ? $dump['raw'] : '',
        ];
    }

    /**
     * The lock the thread waits for at its top frame, and the thread holding it.
     *
     * @param  array<string, mixed>  $thread
     * @param  array<string, string>  $holders
     * @return array{0: string|null, 1: string|null}
     */
    protected function waitingFor(array $thread, array $holders, string $name): array
    {
        foreach ($thread['stack'][0]['locks'] ?? [] as $lock) {
            if (!in_array($lock['type'] ?? null, self::WAITING_LOCKS, true)) {
                continue;
            }

            $address = is_string($lock['address'] ?? null) ? $lock['address'] : null;
            $class = is_string($lock['class'] ?? null) ? $lock['class'] : null;
            $holder = $address !== null ? ($holders[$address] ?? null) : null;

            return [trim(($class ?? '').($address !== null ? " <$address>" : '')) ?: null, $holder !== $name ? $holder : null];
        }

        return [null, null];
    }

    /**
     * Owner of the deepest frame outside the JVM, e.g. a mod's worker thread; null for JVM-only threads.
     *
     * @param  array<string, mixed>  $thread
     * @return array{key: string, name: string, kind: string}|null
     */
    protected function owner(array $thread, ModPackageIndex $index): ?array
    {
        foreach ($thread['stack'] ?? [] as $frame) {
            if (!is_string($frame['method'] ?? null)) {
                continue;
            }

            $key = $index->ownerOf(ModPackageIndex::frameFromDotted($frame['method']));

            if ($key !== ModPackageIndex::JVM) {
                return $index->owner($key);
            }
        }

        return null;
    }

    /**
     * The stack as the JVM prints it: "at method(File.java:123)" and the lock lines below each frame.
     *
     * @param  array<string, mixed>  $thread
     * @return list<string>
     */
    protected function stackLines(array $thread): array
    {
        $lines = [];

        foreach ($thread['stack'] ?? [] as $frame) {
            $method = (string) ($frame['method'] ?? '');
            $module = is_string($frame['module'] ?? null) ? $frame['module'].'/' : '';

            $location = match (true) {
                (bool) ($frame['native'] ?? false) => 'Native Method',
                is_string($frame['file'] ?? null) && isset($frame['line']) => $frame['file'].':'.$frame['line'],
                is_string($frame['file'] ?? null) => $frame['file'],
                default => 'Unknown Source',
            };

            $lines[] = "at $method($module$location)";

            foreach ($frame['locks'] ?? [] as $lock) {
                $lines[] = '  - '.str_replace('_', ' ', (string) ($lock['type'] ?? '')).(is_string($lock['address'] ?? null) ? ' <'.$lock['address'].'>' : '').(is_string($lock['class'] ?? null) ? ' (a '.$lock['class'].')' : '');
            }
        }

        return $lines;
    }
}
