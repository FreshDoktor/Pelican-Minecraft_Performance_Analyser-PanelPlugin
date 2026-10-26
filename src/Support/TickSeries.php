<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * What the widgets under the console show of an answer of GET /ticks (docs/API.md of the bridge 0.3.0):
 * the current TPS and MSPT, how bad they are, and the history for the chart. Plain PHP, so it is tested
 * without Laravel.
 *
 * The bridge samples the Server thread in the first window of a monitor (20 TPS assumed, MSPT is the share
 * of tick work times 50 ms) and counts the ticks from then on: exact TPS, MSPT from the time the Server
 * thread was busy. Bridges before 0.3.0 only count while the server is overloaded. Paused windows keep the
 * values they have (a counting window does), so the line goes on; windows without values (a lag analysis
 * running, an overloaded sampling window) are gaps in the chart, never 0.
 *
 * @phpstan-type Current array{state: string, reason: string|null, tps: float|null, mspt: float|null, load: float|null, overloaded: bool, method: string|null, level: string, stale: bool}
 * @phpstan-type Point array{t: int, v: float|null}
 */
final class TickSeries
{
    /** Below this many TPS the values are a warning, below DANGER_TPS red. */
    public const WARNING_TPS = 18.0;

    public const DANGER_TPS = 15.0;

    public const LEVEL_NORMAL = 'normal';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_DANGER = 'danger';

    /** The current window has values (or is overloaded). */
    public const STATE_VALUES = 'values';

    /** States of the answer that show a text instead of values, strings.ticks.states.<state>. */
    public const STATES = ['starting', 'paused', 'suspended', 'unsupported', 'error'];

    /** Fields of a window the charts show. */
    public const TPS = 'tps';

    public const MSPT = 'tick_time_ms';

    /**
     * The current values. In the state error (the last window failed) the values of the last window that
     * had some, marked stale.
     *
     * @param  array<string, mixed>  $ticks  answer of GET /ticks
     * @return Current
     */
    public static function current(array $ticks): array
    {
        $state = is_string($ticks['state'] ?? null) ? $ticks['state'] : 'error';
        $reason = is_string($ticks['reason'] ?? null) ? $ticks['reason'] : null;
        $window = is_array($ticks['current'] ?? null) ? $ticks['current'] : null;
        $stale = false;

        if ($state === 'error') {
            $window = self::lastWithValues($ticks);
            $stale = $window !== null;
        } elseif ($state !== 'ok') {
            $window = null;
        }

        // Unknown states count like error, as the contract asks for unknown enum values.
        if ($state !== 'ok' && !in_array($state, self::STATES, true)) {
            $state = 'error';
        }

        $tps = self::number($window['tps'] ?? null);
        $mspt = self::number($window['tick_time_ms'] ?? null);
        $overloaded = ($window['overloaded'] ?? false) === true;

        return [
            'state' => $window !== null && ($tps !== null || $mspt !== null || $overloaded) ? self::STATE_VALUES : ($state === 'ok' ? 'starting' : $state),
            'reason' => $reason,
            'tps' => $tps,
            'mspt' => $mspt,
            'load' => self::number($window['load_percent'] ?? null),
            'overloaded' => $overloaded,
            'method' => is_string($window['method'] ?? null) ? $window['method'] : null,
            'level' => $window === null ? self::LEVEL_NORMAL : self::level($tps, $overloaded),
            'stale' => $stale,
        ];
    }

    /**
     * How bad a window is: by its TPS, and a warning when the server has no idle time left but the TPS
     * are not known yet (overloaded sampling, the bridge counts from the next window on).
     */
    public static function level(?float $tps, bool $overloaded): string
    {
        return match (true) {
            $tps !== null && $tps < self::DANGER_TPS => self::LEVEL_DANGER,
            $tps !== null && $tps < self::WARNING_TPS => self::LEVEL_WARNING,
            $tps === null && $overloaded => self::LEVEL_WARNING,
            default => self::LEVEL_NORMAL,
        };
    }

    /**
     * Points of one field of the history (TPS or MSPT), at the end of each window. Windows without a value
     * are null, and so is a point between two windows that are further apart than half a window (the
     * monitor measured nothing in between, e.g. after a lag analysis or a restart of the monitor), so the
     * chart has a gap there instead of a line across.
     *
     * @param  array<string, mixed>  $ticks  answer of GET /ticks
     * @return list<Point>
     */
    public static function points(array $ticks, string $field): array
    {
        $history = is_array($ticks['history'] ?? null) ? $ticks['history'] : [];
        $gap = max(1000, (int) ($ticks['window_ms'] ?? 5000)) / 2;
        $points = [];
        $previousEnd = null;

        foreach ($history as $window) {
            if (!is_array($window) || !is_int($window['ended_at'] ?? null)) {
                continue;
            }

            $start = is_int($window['started_at'] ?? null) ? $window['started_at'] : $window['ended_at'];

            if ($previousEnd !== null && $start - $previousEnd > $gap) {
                $points[] = ['t' => $previousEnd + 1, 'v' => null];
            }

            $value = in_array($window['state'] ?? null, ['ok', 'paused'], true) ? self::number($window[$field] ?? null) : null;
            $points[] = ['t' => $window['ended_at'], 'v' => $value];
            $previousEnd = $window['ended_at'];
        }

        return $points;
    }

    /**
     * Whether a JVM has had long enough to show its tick loop: a server that is still loading its world
     * has no Server thread or no tick method in its samples yet, a big modpack for minutes. The monitor
     * itself must also have looked for a while, one odd window of a monitor that just started doesn't count.
     *
     * @param  array<string, mixed>  $ticks  answer of GET /ticks
     * @param  int|null  $jvmStartedAt  Unix ms, from GET /status
     * @param  int  $monitorMs  how long the monitor must have run (monitor_started_at)
     */
    public static function unsupportedForGood(array $ticks, ?int $jvmStartedAt, int $now, int $afterMs, int $monitorMs = 0): bool
    {
        $monitorStartedAt = $ticks['monitor_started_at'] ?? null;

        return ($ticks['state'] ?? null) === 'unsupported'
            && $jvmStartedAt !== null
            && $now - $jvmStartedAt >= $afterMs
            && ($monitorMs <= 0 || (is_int($monitorStartedAt) && $now - $monitorStartedAt >= $monitorMs));
    }

    /**
     * @param  array<string, mixed>  $ticks
     * @return array<string, mixed>|null
     */
    private static function lastWithValues(array $ticks): ?array
    {
        $history = is_array($ticks['history'] ?? null) ? $ticks['history'] : [];

        foreach (array_reverse($history) as $window) {
            if (is_array($window) && ($window['state'] ?? null) === 'ok') {
                return $window;
            }
        }

        return null;
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
