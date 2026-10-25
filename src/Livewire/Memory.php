<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The tab Memory: class histogram grouped by mod, down to the class. Pauses the server for a moment, so it is only taken on request. Comes with milestone 3.
 */
class Memory extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.memory';

    protected function requiredPermission(): string
    {
        return Permissions::MEMORY;
    }

    public function render(): View
    {
        return view('minecraft-performance-analyser::livewire.memory');
    }
}
