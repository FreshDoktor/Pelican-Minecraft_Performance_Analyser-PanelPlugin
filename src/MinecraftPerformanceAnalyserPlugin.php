<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser;

use App\Models\Server;
use Filament\Contracts\Plugin;
use Filament\Panel;
use FreshDoktor\MinecraftPerformanceAnalyser\Filament\Server\Pages\PerformancePage;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\LagAnalysis;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Memory;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Overview;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Threads;
use Livewire\Livewire;

/**
 * Looks into the JVM of a Minecraft server with the MPA Bridge on its node: heap, GC, threads, lag
 * analysis and memory per mod, on the page "Performance" of the server.
 *
 * The subuser permissions, the address of the bridge per node (admin area) and the activity log texts
 * are registered by the service providers in src/Providers, which the panel loads for every panel.
 */
class MinecraftPerformanceAnalyserPlugin implements Plugin
{
    public const ID = 'minecraft-performance-analyser';

    /** Feature of an egg that turns the page on whatever its tags are. */
    public const EGG_FEATURE = 'performance_analyser';

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() !== 'server') {
            return;
        }

        $panel->pages([PerformancePage::class]);

        // Registered by name so Livewire finds the components again on updates.
        Livewire::component(Overview::NAME, Overview::class);
        Livewire::component(LagAnalysis::NAME, LagAnalysis::class);
        Livewire::component(Memory::NAME, Memory::class);
        Livewire::component(Threads::NAME, Threads::class);
    }

    public function boot(Panel $panel): void {}

    /**
     * Java servers: the egg has the minecraft tag and not bedrock, or the feature performance_analyser.
     * Proxies like Velocity run on Java as well and keep the page.
     */
    public static function isEnabledForServer(Server $server): bool
    {
        $server->loadMissing('egg');

        $features = $server->egg->features ?? [];
        $tags = $server->egg->tags ?? [];

        if (in_array(self::EGG_FEATURE, $features, true)) {
            return true;
        }

        return in_array('minecraft', $tags, true) && !in_array('bedrock', $tags, true);
    }
}
