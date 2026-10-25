<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use App\Facades\Activity;
use FreshDoktor\MinecraftPerformanceAnalyser\Jobs\CreateHistogramJob;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\HistogramAnalyzer;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\ModPackageIndex;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\PackageIndexService;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\SnapshotStore;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * The tab Memory: a class histogram (GC.class_histogram -all through the bridge), grouped by mod and
 * down to the class. Walking the heap pauses the server for a moment, so it is only taken on request,
 * after a warning, and on the queue (CreateHistogramJob), as it may take longer than a web request.
 *
 * The last histogram is kept per server (SnapshotStore) and grouped anew with the current package index
 * whenever the tab renders.
 */
class Memory extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.memory';

    protected function requiredPermission(): string
    {
        return Permissions::MEMORY;
    }

    public function create(): void
    {
        $server = $this->getServer();

        abort_unless($this->userCan(Permissions::MEMORY), 403);

        // The button is disabled then; no job and no activity log entry for a histogram that can't be made.
        if (!$this->capabilities()->available(Capabilities::HISTOGRAM)) {
            return;
        }

        // One at a time per server; Cache::add is atomic.
        $status = ['status' => 'queued', 'queued_at' => time(), 'error' => null];

        if (!Cache::add(CreateHistogramJob::statusKey($server->id), $status, now()->addMinutes(10))) {
            $current = CreateHistogramJob::status($server->id);

            if (in_array($current['status'] ?? null, ['queued', 'running'], true)) {
                return;
            }

            cache()->put(CreateHistogramJob::statusKey($server->id), $status, now()->addMinutes(10));
        }

        try {
            CreateHistogramJob::dispatch($server->id, user()?->id);
        } catch (Throwable $exception) {
            report($exception);
            cache()->forget(CreateHistogramJob::statusKey($server->id));

            return;
        }

        Activity::event('server:performance.histogram')
            ->subject($server)
            ->log();
    }

    /**
     * Called by wire:poll while the histogram is being created, the view reads the status anew.
     */
    public function tick(): void {}

    public function dismissError(): void
    {
        $status = CreateHistogramJob::status($this->getServer()->id);

        if (($status['status'] ?? null) === 'failed') {
            cache()->forget(CreateHistogramJob::statusKey($this->getServer()->id));
        }
    }

    /**
     * @return array{status: string, queued_at: int, error: array<string, mixed>|null}|null
     */
    public function histogramStatus(): ?array
    {
        return CreateHistogramJob::status($this->getServer()->id);
    }

    /**
     * The error of a failed histogram, translated in the user's language where the code is known.
     *
     * @param  array<string, mixed>|null  $error
     */
    public function errorMessage(?array $error): string
    {
        if ($error === null) {
            return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected');
        }

        return $this->bridge()->exceptionFromError($error)->getMessage();
    }

    public function render(): View
    {
        $server = $this->getServer();
        $stored = app(SnapshotStore::class)->get($server, SnapshotStore::HISTOGRAM);
        $analysis = null;

        if ($stored !== null) {
            try {
                $index = app(PackageIndexService::class)->index($server);
            } catch (Throwable $exception) {
                report($exception);
                $index = new ModPackageIndex;
            }

            $analysis = app(HistogramAnalyzer::class)->analyze($stored['data'], $index);
        }

        return view('minecraft-performance-analyser::livewire.memory', [
            'status' => $this->histogramStatus(),
            'stored' => $stored,
            'analysis' => $analysis,
            'canCreate' => $this->userCan(Permissions::MEMORY),
            'availability' => $this->availability(Capabilities::HISTOGRAM),
        ]);
    }
}
