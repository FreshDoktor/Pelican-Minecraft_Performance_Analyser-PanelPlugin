<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns;

use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\BridgeClient;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ServerCapabilities;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
use Livewire\Attributes\Locked;

/**
 * A tab of the performance page. Updates of a Livewire component don't pass through the page that
 * rendered it, so every request checks again that the user may still see the server and this tab.
 */
trait BelongsToServer
{
    /** Seconds between two looks while an action is disabled, e.g. until the server is started. */
    public const POLL_UNAVAILABLE_SECONDS = 15;

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

    protected function capabilities(): Capabilities
    {
        return app(ServerCapabilities::class)->for($this->getServer());
    }

    /**
     * Ask the bridge again on the next render, e.g. after it refused an action: the server may have stopped
     * in the meantime.
     */
    protected function forgetCapabilities(): void
    {
        app(ServerCapabilities::class)->forget($this->getServer());
    }

    /**
     * Whether an action can be done now, for the view: reason null means it can. A disabled action (server
     * stopped, JVM starting, -Xrs, …) is worth asking again after a while, see POLL_UNAVAILABLE_SECONDS.
     *
     * @return array{reason: ?string, text: ?string, disabled: bool}
     */
    public function availability(string $action): array
    {
        $capabilities = $this->capabilities();
        $reason = $capabilities->reason($action);

        return [
            'reason' => $reason,
            'text' => $reason === null ? null : trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.capabilities.'.$reason),
            'disabled' => $capabilities->disabled($action),
        ];
    }
}
