<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Server\Widgets;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\TickSeries;

/**
 * Ticks per second under the console. While the server keeps up the bridge assumes 20 and the hint says
 * how busy the tick loop is; once it falls behind the bridge counts the ticks.
 */
class TpsChart extends TickChart
{
    public const NAME = 'minecraft-performance-analyser.tps-chart';

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
        return match (true) {
            $current['tps'] === null && $current['overloaded'] => $this->trans('tps_overloaded'),
            $current['method'] === 'counting' => $this->trans($current['overloaded'] ? 'tps_counting' : 'tps_counting_ok'),
            $current['load'] !== null => $this->trans('tps_sampling', ['load' => Format::percent($current['load'])]),
            default => null,
        };
    }
}
