<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Filament\Server\Pages;

use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\LagAnalysis;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Memory;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Overview;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Threads;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;

/**
 * The page "Performance" of a server. Each tab is a Livewire component of its own (src/Livewire), which
 * talks to the bridge itself and checks the permissions again on every update.
 *
 * Nothing on this page asks the bridge by just opening it, except the overview: thread dumps and
 * histograms stop the JVM for a moment and are only taken on request.
 */
class PerformancePage extends Page
{
    use BlockAccessInConflict {
        canAccess as canAccessWhenNotInConflict;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-activity-heartbeat';

    protected static ?string $slug = 'performance';

    protected static ?int $navigationSort = 33;

    public static function canAccess(): bool
    {
        $server = Filament::getTenant();

        // The tenant is checked first, BlockAccessInConflict expects a server.
        return $server instanceof Server
            && static::canAccessWhenNotInConflict()
            && MinecraftPerformanceAnalyserPlugin::isEnabledForServer($server)
            && Permissions::check(Permissions::READ, $server);
    }

    public static function getNavigationLabel(): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.navigation_label');
    }

    public function getTitle(): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.title');
    }

    protected function getServer(): Server
    {
        $server = Filament::getTenant();

        abort_unless($server instanceof Server, 404);

        return $server;
    }

    public function content(Schema $schema): Schema
    {
        $server = $this->getServer();

        return $schema->components([
            Tabs::make('performance')
                ->persistTabInQueryString()
                ->contained(false)
                ->columnSpanFull()
                ->tabs([
                    $this->tab('overview', 'tabler-gauge', Overview::NAME, $server),
                    // Past results only need read, starting a profile is checked by the tab itself.
                    $this->tab('lag', 'tabler-flame', LagAnalysis::NAME, $server),
                    $this->tab('memory', 'tabler-database', Memory::NAME, $server)
                        ->visible(fn () => Permissions::check(Permissions::MEMORY, $server)),
                    $this->tab('threads', 'tabler-list-tree', Threads::NAME, $server)
                        ->visible(fn () => Permissions::check(Permissions::THREADS, $server)),
                ]),
        ]);
    }

    /**
     * @param  string  $component  name the Livewire component is registered under, see MinecraftPerformanceAnalyserPlugin
     */
    protected function tab(string $id, string $icon, string $component, Server $server): Tab
    {
        return Tab::make(trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.tabs.'.$id))
            ->id($id)
            ->icon($icon)
            ->schema([
                Livewire::make($component, ['serverId' => $server->id])
                    ->key('mpa-'.$id),
            ]);
    }
}
