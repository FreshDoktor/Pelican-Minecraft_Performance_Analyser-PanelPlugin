<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns;

use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\BridgeClient;
use Livewire\Attributes\Locked;

/**
 * A tab of the performance page. Updates of a Livewire component don't pass through the page that
 * rendered it, so every request checks again that the user may still see the server and this tab.
 */
trait BelongsToServer
{
    #[Locked]
    public int $serverId;

    protected ?Server $server = null;

    /** Permission needed to see the tab at all, see Permissions. */
    abstract protected function requiredPermission(): string;

    public function mount(int $serverId): void
    {
        $this->serverId = $serverId;

        $this->authorizeAccess();
    }

    public function hydrateBelongsToServer(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        $server = $this->getServer();

        abort_unless(
            (user()?->canAccessTenant($server) ?? false)
            && !$server->isInConflictState()
            && MinecraftPerformanceAnalyserPlugin::isEnabledForServer($server)
            && Permissions::check($this->requiredPermission(), $server),
            403,
        );
    }

    protected function getServer(): Server
    {
        return $this->server ??= Server::findOrFail($this->serverId);
    }

    protected function userCan(string $permission): bool
    {
        return Permissions::check($permission, $this->getServer());
    }

    protected function bridge(): BridgeClient
    {
        return app(BridgeClient::class);
    }
}
