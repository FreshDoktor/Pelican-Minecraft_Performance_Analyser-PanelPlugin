<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Server\Widgets;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\TickSeries;

/**
 * Milliseconds per tick under the console: how long the server works on one tick on average. Up to 50 ms
 * it keeps 20 TPS.
 */
class MsptChart extends TickChart
{
    public const NAME = 'minecraft-performance-analyser.mspt-chart';

    protected function field(): string
    {
        return TickSeries::MSPT;
    }

    protected function labelKey(): string
    {
        return 'mspt';
    }

    protected function suggestedMax(): int
    {
        return 50;
    }

    protected function value(array $current): ?string
    {
        return match (true) {
            $current['mspt'] !== null => $this->approx($current['mspt'], ' ms'),
            $current['overloaded'] => $this->trans('overloaded'),
            default => '–',
        };
    }

    protected function hint(array $current): ?string
    {
        return $this->trans(match (true) {
            $current['mspt'] === null && !$current['overloaded'] => 'mspt_unknown',
            // From the busy time of the Server thread (bridge 0.3.0); overloaded it is the window per tick.
            $current['method'] === 'counting' && !$current['overloaded'] => 'mspt_busy',
            default => 'mspt_hint',
        });
    }
}
