<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The tab Threads: thread dump filtered by state, deadlocks, CPU time per thread, raw text. Only taken on request. Comes with milestone 3.
 */
class Threads extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.threads';

    protected function requiredPermission(): string
    {
        return Permissions::THREADS;
    }

    public function render(): View
    {
        return view('minecraft-performance-analyser::livewire.threads');
    }
}
