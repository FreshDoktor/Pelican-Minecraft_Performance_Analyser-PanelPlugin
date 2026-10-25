<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Providers;

use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use FreshDoktor\MinecraftPerformanceAnalyser\Filament\Admin\NodeBridgeTab;
use Illuminate\Support\ServiceProvider;

/**
 * Adds the tab "MPA Bridge" to the edit page of a node in the admin area. Done here and not in the plugin
 * class, as the plugin is only registered for the server panel (plugin.json), while service providers are
 * loaded for every panel.
 */
class NodeBridgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        EditNode::registerCustomTabs(TabPosition::After, NodeBridgeTab::make());
    }
}
