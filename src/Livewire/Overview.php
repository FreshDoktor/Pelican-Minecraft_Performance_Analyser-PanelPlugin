<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The tab Overview: live heap and GC values, the JVM at a glance and the diagnosis.
 */
class Overview extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.overview';

    protected function requiredPermission(): string
    {
        return Permissions::READ;
    }

    public function render(): View
    {
        return view('minecraft-performance-analyser::livewire.overview');
    }
}
