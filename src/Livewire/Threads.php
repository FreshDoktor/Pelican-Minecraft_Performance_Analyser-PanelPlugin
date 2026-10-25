<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use App\Facades\Activity;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\PackageIndexService;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\SnapshotStore;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ThreadDumpAnalyzer;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The tab Threads: a thread dump (Thread.print -l through the bridge) on request, with a filter by state,
 * deadlocks first, CPU time per thread, the mod a thread works for, and the raw text to copy.
 *
 * The dump costs a short safepoint and takes the bridge at most 15 s, so it is asked for in the request
 * itself. The last dump is kept per server (SnapshotStore). Filtering and opening stacks happen in the
 * browser, they need no request.
 */
class Threads extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.threads';

    /** The bridge's 15 s for Thread.print plus the way there and back. */
    public const BRIDGE_TIMEOUT = 25;

    #[Locked]
    public ?string $error = null;

    protected function requiredPermission(): string
    {
        return Permissions::THREADS;
    }

    public function create(): void
    {
        $server = $this->getServer();

        abort_unless($this->userCan(Permissions::THREADS), 403);

        $this->error = null;

        try {
            $dump = $this->bridge()->get($server, 'threads', [], self::BRIDGE_TIMEOUT);
        } catch (BridgeException $exception) {
            $this->error = $exception->getMessage();
            $this->forgetCapabilities();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->error = trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected');

            return;
        }

        app(SnapshotStore::class)->put($server, SnapshotStore::THREADS, $dump, user());

        Activity::event('server:performance.threads')
            ->subject($server)
            ->log();
    }

    public function render(): View
    {
        $server = $this->getServer();
        $stored = app(SnapshotStore::class)->get($server, SnapshotStore::THREADS);
        $analysis = null;

        if ($stored !== null) {
            try {
                $index = app(PackageIndexService::class)->index($server);
            } catch (Throwable $exception) {
                report($exception);
                $index = new ModPackageIndex;
            }

            $analysis = app(ThreadDumpAnalyzer::class)->analyze($stored['data'], $index);
        }

        return view('minecraft-performance-analyser::livewire.threads', [
            'stored' => $stored,
            'analysis' => $analysis,
            'availability' => $this->availability(Capabilities::THREADS),
        ]);
    }
}
