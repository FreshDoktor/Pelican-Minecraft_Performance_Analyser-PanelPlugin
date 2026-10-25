<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * The samples of /metrics the overview keeps while it is open, for the charts and the rule "high GC share".
 *
 * The bridge's counters are cumulative since the JVM started (docs/API.md, GET /metrics), rates are the
 * difference of two samples divided by the difference of their sampled_at. A smaller uptime_ms than
 * before means the JVM restarted, the history starts over then.
 *
 * Heap values are the state after the last GC (source after_gc), not live values.
 *
 * @phpstan-type Sample array{t: int, uptime: int|null, young: int, old: int, max: int, gc_pause_ms: float}
 */
final class MetricsHistory
{
    /**
     * The values of a /metrics answer the overview keeps.
     *
     * @param  array<string, mixed>  $metrics
     * @return Sample
     */
    public static function sample(array $metrics): array
    {
        $young = 0;
        $old = 0;

        foreach ($metrics['generations'] ?? [] as $generation) {
            if (($generation['kind'] ?? null) === 'young') {
                $young += (int) ($generation['used_bytes'] ?? 0);
            } else {
                // old, and the single generation of Shenandoah (kind heap)
                $old += (int) ($generation['used_bytes'] ?? 0);
            }
        }

        return [
            't' => (int) ($metrics['sampled_at'] ?? 0),
            'uptime' => isset($metrics['uptime_ms']) ? (int) $metrics['uptime_ms'] : null,
            'young' => $young,
            'old' => $old,
            'max' => (int) ($metrics['heap']['max_bytes'] ?? 0),
            'gc_pause_ms' => self::pauseTime($metrics),
        ];
    }

    /**
     * GC time that stopped the server, in ms since the JVM started. Only collectors whose time measures
     * pauses count (time_is_pause), concurrent cycles of G1 or ZGC don't stop the server. Without any
     * such collector (Shenandoah: unknown) the total GC time is taken.
     *
     * @param  array<string, mixed>  $metrics
     */
    public static function pauseTime(array $metrics): float
    {
        $time = 0.0;
        $found = false;

        foreach ($metrics['gc']['collectors'] ?? [] as $collector) {
            if (($collector['time_is_pause'] ?? null) === true) {
                $time += (float) ($collector['time_ms'] ?? 0);
                $found = true;
            }
        }

        return $found ? $time : (float) ($metrics['gc']['time_ms'] ?? 0);
    }

    /**
     * Add a sample, keep the newest $max ones.
     *
     * @param  list<Sample>  $history
     * @param  Sample  $sample
     * @return list<Sample>
     */
    public static function push(array $history, array $sample, int $max): array
    {
        $last = $history === [] ? null : $history[array_key_last($history)];

        if ($last !== null && self::restarted($last, $sample)) {
            return [$sample];
        }

        // The bridge answered from the same reading, nothing new.
        if ($last !== null && $sample['t'] <= $last['t']) {
            return $history;
        }

        $history[] = $sample;

        return array_values(array_slice($history, -max(1, $max)));
    }

    /**
     * @param  Sample  $previous
     * @param  Sample  $sample
     */
    public static function restarted(array $previous, array $sample): bool
    {
        return $previous['uptime'] !== null && $sample['uptime'] !== null && $sample['uptime'] < $previous['uptime'];
    }

    /**
     * GC pause time per minute between each two samples, in ms per minute, at the time of the later one.
     *
     * @param  list<Sample>  $history
     * @return list<array{t: int, value: float}>
     */
    public static function gcPerMinute(array $history): array
    {
        $rates = [];

        for ($i = 1; $i < count($history); $i++) {
            $span = $history[$i]['t'] - $history[$i - 1]['t'];

            if ($span <= 0) {
                continue;
            }

            $rates[] = [
                't' => $history[$i]['t'],
                'value' => max(0.0, ($history[$i]['gc_pause_ms'] - $history[$i - 1]['gc_pause_ms']) / $span * 60000),
            ];
        }

        return $rates;
    }

    /**
     * Share of the time the server spent in GC pauses over the whole history, in percent. Null if the
     * history covers less than $minSpanMs, a few seconds say nothing.
     *
     * @param  list<Sample>  $history
     */
    public static function gcShare(array $history, int $minSpanMs): ?float
    {
        if (count($history) < 2) {
            return null;
        }

        $first = $history[0];
        $last = $history[count($history) - 1];
        $span = $last['t'] - $first['t'];

        if ($span < $minSpanMs || $span <= 0) {
            return null;
        }

        return max(0.0, ($last['gc_pause_ms'] - $first['gc_pause_ms']) / $span * 100);
    }

    /**
     * Milliseconds the history covers.
     *
     * @param  list<Sample>  $history
     */
    public static function span(array $history): int
    {
        return count($history) < 2 ? 0 : $history[count($history) - 1]['t'] - $history[0]['t'];
    }
}
