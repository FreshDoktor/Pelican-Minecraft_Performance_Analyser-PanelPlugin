<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\TickSeries as T;
use PHPUnit\Framework\TestCase;

/**
 * What the widgets under the console show, from the example answers of GET /ticks (bridge 0.3.0).
 */
class TickSeriesTest extends TestCase
{
    public function test_a_server_that_keeps_up(): void
    {
        $ticks = DiagnosticsTest::example('ticks');
        $current = T::current($ticks);

        $this->assertSame(T::STATE_VALUES, $current['state']);
        $this->assertSame(20.0, $current['tps']);
        $this->assertSame(4.82, $current['mspt']);
        $this->assertSame(9.64, $current['load']);
        $this->assertSame('counting', $current['method']);
        $this->assertSame(T::LEVEL_NORMAL, $current['level']);
        $this->assertFalse($current['stale']);

        // Windows follow each other (5 ms apart, sampling then counting): no gap.
        $this->assertSame([4.2, 4.46, 4.82], array_column(T::points($ticks, T::MSPT), 'v'));
        $this->assertSame([1792930104995, 1792930109995, 1792930114995], array_column(T::points($ticks, T::MSPT), 't'));
        $this->assertSame([20.0, 20.02, 20.0], array_column(T::points($ticks, T::TPS), 'v'));
    }

    public function test_an_overloaded_server_is_counted(): void
    {
        $ticks = DiagnosticsTest::example('ticks-overloaded');
        $current = T::current($ticks);

        $this->assertSame(14.4, $current['tps']);
        $this->assertSame(69.444, $current['mspt']);
        $this->assertSame('counting', $current['method']);
        $this->assertTrue($current['overloaded']);
        $this->assertSame(T::LEVEL_DANGER, $current['level']);

        // The overloaded sampling window knows neither: a gap, not 0.
        $this->assertSame([null, 14.21, 14.4], array_column(T::points($ticks, T::TPS), 'v'));
        $this->assertSame([null, 70.354, 69.444], array_column(T::points($ticks, T::MSPT), 'v'));
    }

    public function test_an_overloaded_sampling_window_is_a_warning_without_numbers(): void
    {
        $ticks = DiagnosticsTest::example('ticks-overloaded');
        $ticks['current'] = $ticks['history'][0];
        $current = T::current($ticks);

        $this->assertSame(T::STATE_VALUES, $current['state']);
        $this->assertNull($current['tps']);
        $this->assertNull($current['mspt']);
        $this->assertTrue($current['overloaded']);
        $this->assertSame(T::LEVEL_WARNING, $current['level']);
    }

    public function test_levels(): void
    {
        $this->assertSame(T::LEVEL_NORMAL, T::level(20.0, false));
        $this->assertSame(T::LEVEL_NORMAL, T::level(18.0, false));
        $this->assertSame(T::LEVEL_WARNING, T::level(17.9, true));
        $this->assertSame(T::LEVEL_WARNING, T::level(15.0, true));
        $this->assertSame(T::LEVEL_DANGER, T::level(14.9, true));
        $this->assertSame(T::LEVEL_WARNING, T::level(null, true));
        $this->assertSame(T::LEVEL_NORMAL, T::level(null, false));
    }

    public function test_states_without_values(): void
    {
        $cases = [
            'ticks-paused' => ['paused', null],
            'ticks-starting' => ['starting', 'first_window'],
            'ticks-jvm-starting' => ['starting', 'jvm_starting'],
            'ticks-suspended' => ['suspended', 'profile_running'],
            'ticks-unsupported' => ['unsupported', 'no_server_thread'],
        ];

        foreach ($cases as $example => [$state, $reason]) {
            $current = T::current(DiagnosticsTest::example($example));

            $this->assertSame($state, $current['state'], $example);
            $this->assertSame($reason, $current['reason'], $example);
            $this->assertNull($current['tps'], $example);
            $this->assertNull($current['mspt'], $example);
            $this->assertSame(T::LEVEL_NORMAL, $current['level'], $example);
        }

        // Paused: the counting window keeps its values, the line goes on.
        $this->assertSame([null, 20.02], array_column(T::points(DiagnosticsTest::example('ticks-paused'), T::TPS), 'v'));
        $this->assertSame([null, 0.05], array_column(T::points(DiagnosticsTest::example('ticks-paused'), T::MSPT), 'v'));
        $this->assertSame([], T::points(DiagnosticsTest::example('ticks-starting'), T::TPS));
        // The lag analysis is a gap after the last value.
        $this->assertSame([4.2, null], array_column(T::points(DiagnosticsTest::example('ticks-suspended'), T::MSPT), 'v'));
    }

    public function test_a_failed_window_keeps_the_last_values(): void
    {
        $ticks = DiagnosticsTest::example('ticks');
        $ticks['state'] = 'error';
        $ticks['error'] = ['error' => 'attach_timeout', 'message' => 'The JVM did not answer in time.', 'details' => null];

        $current = T::current($ticks);
        $this->assertSame(T::STATE_VALUES, $current['state']);
        $this->assertSame(4.82, $current['mspt']);
        $this->assertTrue($current['stale']);

        $ticks['history'] = [];
        $ticks['current'] = null;
        $this->assertSame('error', T::current($ticks)['state']);

        // A state of a newer bridge counts as error.
        $this->assertSame('error', T::current(['state' => 'frozen', 'current' => null, 'history' => []])['state']);
        $this->assertSame('error', T::current([])['state']);
    }

    public function test_windows_far_apart_leave_a_gap(): void
    {
        $ticks = DiagnosticsTest::example('ticks');
        // The monitor measured nothing for 30 s before the last window.
        $ticks['history'][2]['started_at'] += 30000;
        $ticks['history'][2]['ended_at'] += 30000;

        $points = T::points($ticks, T::MSPT);

        $this->assertSame([4.2, 4.46, null, 4.82], array_column($points, 'v'));
        $this->assertSame(1792930109996, $points[2]['t']);
    }

    public function test_a_jvm_without_tick_loop_for_long_is_remembered(): void
    {
        $unsupported = DiagnosticsTest::example('ticks-unsupported');
        $now = $unsupported['sampled_at'];
        $after = 600000;

        $this->assertTrue(T::unsupportedForGood($unsupported, $now - $after, $now, $after));
        // Still loading its world, maybe.
        $this->assertFalse(T::unsupportedForGood($unsupported, $now - 300000, $now, $after));
        $this->assertFalse(T::unsupportedForGood($unsupported, null, $now, $after));
        $this->assertFalse(T::unsupportedForGood(DiagnosticsTest::example('ticks'), $now - 3600000, $now, $after));
        // A monitor that just started (the example's first window) doesn't decide yet.
        $this->assertFalse(T::unsupportedForGood($unsupported, $now - $after, $now, $after, 60000));
        $looked = ['monitor_started_at' => $now - 60000] + $unsupported;
        $this->assertTrue(T::unsupportedForGood($looked, $now - $after, $now, $after, 60000));
        $this->assertFalse(T::unsupportedForGood(['monitor_started_at' => null] + $unsupported, $now - $after, $now, $after, 60000));
    }

    public function test_every_state_has_a_text_in_both_languages(): void
    {
        foreach (['en', 'de'] as $locale) {
            $strings = require dirname(__DIR__, 2)."/lang/$locale/strings.php";

            foreach ([...T::STATES, 'jvm_starting', 'no_server_thread'] as $state) {
                $this->assertArrayHasKey($state, $strings['ticks']['states'], "$locale: ticks.states.$state");
            }
        }
    }
}
