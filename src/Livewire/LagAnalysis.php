<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The tab Lag analysis: start a profile (10 / 30 / 60 s), share of the tick time per mod, top methods, flamegraph. Seeing past results needs performance.read, starting a profile performance.profile. Comes with milestone 2.
 */
class LagAnalysis extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.lag-analysis';

    protected function requiredPermission(): string
    {
        return Permissions::READ;
    }

    public function render(): View
    {
        return view('minecraft-performance-analyser::livewire.lag-analysis');
    }
}
