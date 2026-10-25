<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\MetricsHistory;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\SvgChart;
use PHPUnit\Framework\TestCase;

class MetricsHistoryTest extends TestCase
{
    public function test_sample_of_the_example_metrics(): void
    {
        $sample = MetricsHistory::sample(DiagnosticsTest::example('metrics'));

        $this->assertSame(1792929810000, $sample['t']);
        $this->assertSame(63214288, $sample['uptime']);
        $this->assertSame(5038192, $sample['young']);
        $this->assertSame(512237008, $sample['old']);
        $this->assertSame(2000683008, $sample['max']);
        // Both Serial collectors measure pauses: 2700.557 + 526.444
        $this->assertEqualsWithDelta(3227.001, $sample['gc_pause_ms'], 0.0001);
    }

    public function test_concurrent_cycles_are_no_pauses(): void
    {
        $metrics = ['gc' => ['time_ms' => 900.0, 'collectors' => [
            ['time_is_pause' => true, 'time_ms' => 100.0],
            ['time_is_pause' => false, 'time_ms' => 800.0],
        ]]];

        $this->assertSame(100.0, MetricsHistory::pauseTime($metrics));
    }

    public function test_without_known_pauses_the_whole_gc_time_counts(): void
    {
        $metrics = ['gc' => ['time_ms' => 900.0, 'collectors' => [['time_is_pause' => null, 'time_ms' => 900.0]]]];

        $this->assertSame(900.0, MetricsHistory::pauseTime($metrics));
    }

    public function test_push_keeps_the_newest_and_starts_over_after_a_restart(): void
    {
        $history = [];

        for ($i = 0; $i < 5; $i++) {
            $history = MetricsHistory::push($history, self::sample(1000 * $i, 5000 + 1000 * $i, 10.0 * $i), 3);
        }

        $this->assertSame([2000, 3000, 4000], array_column($history, 't'));

        // The same reading again changes nothing.
        $this->assertSame($history, MetricsHistory::push($history, self::sample(4000, 9000, 40.0), 3));

        // Uptime went down: a new JVM.
        $restarted = MetricsHistory::push($history, self::sample(5000, 100, 0.0), 3);
        $this->assertSame([5000], array_column($restarted, 't'));
    }

    public function test_gc_per_minute_and_share(): void
    {
        $history = [
            self::sample(0, 1000, 0.0),
            self::sample(3000, 4000, 30.0),
            self::sample(6000, 7000, 30.0),
        ];

        // 30 ms in 3 s = 600 ms per minute, then nothing.
        $this->assertEquals([['t' => 3000, 'value' => 600.0], ['t' => 6000, 'value' => 0.0]], MetricsHistory::gcPerMinute($history));
        // 30 ms in 6 s = 0.5 %
        $this->assertEqualsWithDelta(0.5, MetricsHistory::gcShare($history, 1), 0.0001);
        $this->assertNull(MetricsHistory::gcShare($history, 60000));
        $this->assertSame(6000, MetricsHistory::span($history));
    }

    public function test_chart_steps(): void
    {
        $this->assertSame('0,100 100,100 100,0 100,0', SvgChart::line([0, 10], [0, 10], 10, 100, 100));
        // A single reading fills the width.
        $this->assertSame('0,50 100,50', SvgChart::line([5], [5], 10, 100, 100));
        $this->assertSame('', SvgChart::band([], [], [], 10, 100, 100));
        $this->assertSame(0.0, SvgChart::y(20, 10, 100));
    }

    /**
     * @return array{t: int, uptime: int, young: int, old: int, max: int, gc_pause_ms: float}
     */
    protected static function sample(int $t, int $uptime, float $gc): array
    {
        return ['t' => $t, 'uptime' => $uptime, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => $gc];
    }
}
