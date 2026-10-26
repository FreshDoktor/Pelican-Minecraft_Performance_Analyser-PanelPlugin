<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Server\Widgets;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\TickSeries;

/**
 * Ticks per second under the console. The bridge counts them (from 0.3.0 after its first window, before
 * only while the server falls behind); while it samples it assumes 20 and the hint says how busy the
 * tick loop is.
 */
class TpsChart extends TickChart
{
    public const NAME = 'minecraft-performance-analyser.tps-chart';

    /** Above this many counted TPS the server runs ticks it fell behind on back to back. */
    public const CATCHING_UP_TPS = 20.5;

    protected function field(): string
    {
        return TickSeries::TPS;
    }

    protected function labelKey(): string
    {
        return 'tps';
    }

    protected function suggestedMax(): int
    {
        return 20;
    }

    protected function value(array $current): ?string
    {
        return match (true) {
            $current['tps'] !== null => $this->approx($current['tps']),
            $current['overloaded'] => $this->trans('overloaded'),
            default => null,
        };
    }

    protected function hint(array $current): ?string
    {
        $counting = $current['method'] === 'counting';

        return match (true) {
            $current['tps'] === null && $current['overloaded'] => $this->trans('tps_overloaded'),
            $counting && $current['overloaded'] => $this->trans('tps_counting'),
            $counting && $current['tps'] !== null && $current['tps'] > self::CATCHING_UP_TPS => $this->trans('tps_catching_up'),
            $counting && $current['load'] !== null => $this->trans('tps_counting_load', ['load' => Format::percent($current['load'])]),
            $counting => $this->trans('tps_counting_ok'),
            $current['load'] !== null => $this->trans('tps_sampling', ['load' => Format::percent($current['load'])]),
            default => null,
        };
    }
}
