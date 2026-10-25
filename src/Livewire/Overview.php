<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Livewire;

use App\Filament\Admin\Resources\Nodes\Pages\EditNode;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\Filament\Admin\NodeBridgeTab;
use FreshDoktor\MinecraftPerformanceAnalyser\Livewire\Concerns\BelongsToServer;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Permissions;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\BridgeClient;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\Diagnostics;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MetricsHistory;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\SvgChart;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * The tab Overview: live heap and GC values, the JVM at a glance and the diagnosis.
 *
 * Polls GET /metrics of the bridge every few seconds while the tab is visible (wire:poll.visible), and
 * GET /vm once per JVM start. Reading both costs the JVM nothing, the bridge reads hsperfdata. The heap
 * values are the state after the last GC, the charts say so.
 *
 * The samples are kept in the component (locked, the browser can't change them) as long as the page is
 * open, nothing is stored.
 *
 * @phpstan-import-type Sample from MetricsHistory
 */
class Overview extends Component
{
    use BelongsToServer;

    public const NAME = 'minecraft-performance-analyser.overview';

    /** Samples kept for the charts, at 3 s about 5 minutes. */
    public const HISTORY_SIZE = 100;

    /** Seconds between two tries while the server is stopped or Java not up yet. */
    public const NOT_READY_POLL_SECONDS = 10;

    /** Seconds between two readings of the live heap, see config live_interval. */
    public const DEFAULT_LIVE_INTERVAL = 30;

    public const MIN_LIVE_INTERVAL = 10;

    /** Seconds until VM.flags are asked for again when they failed for a passing reason. */
    public const VM_RETRY_SECONDS = 30;

    public const STATE_LOADING = 'loading';

    public const STATE_READY = 'ready';

    /** The bridge answers, but the server is stopped, has no Java process or no hsperfdata. */
    public const STATE_NOT_READY = 'not_ready';

    /** No bridge answers for this node, the tab tells how to install one. */
    public const STATE_BRIDGE_MISSING = 'bridge_missing';

    public const STATE_ERROR = 'error';

    #[Locked]
    public string $state = self::STATE_LOADING;

    /** Error code of the bridge while not ready, e.g. server_not_running. */
    #[Locked]
    public ?string $notReadyCode = null;

    #[Locked]
    public ?string $error = null;

    /** @var array<string, mixed>|null answer of GET /vm, fetched once per JVM start */
    #[Locked]
    public ?array $vm = null;

    #[Locked]
    public ?string $vmError = null;

    /** When /vm was fetched (Unix seconds), to ask again for VM.flags while the JVM starts. */
    #[Locked]
    public ?int $vmFetchedAt = null;

    /** @var array<string, mixed>|null live_heap of the last GET /metrics?live=1 */
    #[Locked]
    public ?array $liveHeap = null;

    /** When the live heap was asked for last (Unix seconds). */
    #[Locked]
    public ?int $liveAt = null;

    /** live_heap_error of the last try, e.g. attach_unavailable. */
    #[Locked]
    public ?string $liveError = null;

    /** False once the bridge answered not_implemented for the live heap. */
    #[Locked]
    public bool $liveSupported = true;

    /** @var array<string, mixed>|null last answer of GET /metrics */
    #[Locked]
    public ?array $metrics = null;

    /** @var list<Sample> */
    #[Locked]
    public array $history = [];

    protected function requiredPermission(): string
    {
        return Permissions::READ;
    }

    /**
     * Read the metrics again, called by wire:init and wire:poll. Every live_interval seconds with the live
     * heap (?live=1), see wantsLive().
     */
    public function refresh(): void
    {
        $server = $this->getServer();
        $live = $this->wantsLive();

        try {
            $metrics = $this->bridge()->metrics($server, $live);
        } catch (BridgeException $exception) {
            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail(new BridgeException(BridgeException::BRIDGE_ERROR, trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected')));

            return;
        }

        $sample = MetricsHistory::sample($metrics);
        $last = $this->history === [] ? null : $this->history[array_key_last($this->history)];

        // A new JVM has other start arguments and maybe other limits, and its old live heap is gone.
        if ($last !== null && MetricsHistory::restarted($last, $sample)) {
            $this->vm = null;
            $this->liveHeap = null;
            $this->liveAt = null;
        }

        if ($live) {
            $this->takeLiveHeap($metrics);
        }

        $this->history = MetricsHistory::push($this->history, $sample, self::HISTORY_SIZE);
        $this->metrics = $metrics;
        $this->state = self::STATE_READY;
        $this->notReadyCode = null;
        $this->error = null;

        if ($this->vm === null || $this->shouldReloadVm()) {
            $this->loadVm();
        }
    }

    /**
     * Whether this poll asks for the live heap. ?live=1 costs the JVM two attach commands (GC.heap_info
     * with a short safepoint, VM.metaspace), so not on every poll of 2 to 5 s but every live_interval
     * seconds (30 by default), and never again once the bridge says it can't.
     */
    protected function wantsLive(): bool
    {
        $interval = (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.live_interval', self::DEFAULT_LIVE_INTERVAL);

        if (!$this->liveSupported || $interval <= 0) {
            return false;
        }

        // The bridge won't attach to this JVM (-XX:+DisableAttachMechanism, -Xrs): no use asking.
        if (in_array($this->vm['attach']['reason'] ?? null, ['disabled', 'xrs'], true)) {
            return false;
        }

        return $this->liveAt === null || time() - $this->liveAt >= max(self::MIN_LIVE_INTERVAL, $interval);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    protected function takeLiveHeap(array $metrics): void
    {
        $this->liveAt = time();
        $error = $metrics['live_heap_error'] ?? null;

        if (is_array($metrics['live_heap'] ?? null)) {
            $this->liveHeap = $metrics['live_heap'];
        } elseif ($error === 'not_implemented') {
            // A bridge before its milestone 2.
            $this->liveSupported = false;
            $this->liveHeap = null;
        } else {
            // attach_unavailable while the JVM starts, a timeout: keep the last reading, try again next time.
            $this->liveError = is_string($error) ? $error : null;
        }
    }

    /**
     * VM.flags failed for a reason that passes (the JVM is just starting, a timeout): ask again after a while.
     */
    protected function shouldReloadVm(): bool
    {
        if ($this->vm === null || ($this->vm['flags'] ?? null) !== null || $this->vmFetchedAt === null) {
            return false;
        }

        $error = $this->vm['flags_error'] ?? null;
        $passes = in_array($error, ['attach_timeout', 'attach_failed'], true)
            || ($error === 'attach_unavailable' && ($this->vm['attach']['reason'] ?? null) === 'jvm_starting');

        return $passes && time() - $this->vmFetchedAt >= self::VM_RETRY_SECONDS;
    }

    protected function loadVm(): void
    {
        $this->vmFetchedAt = time();

        try {
            $this->vm = $this->bridge()->vm($this->getServer());
            $this->vmError = null;
        } catch (BridgeException $exception) {
            // The metrics came through, so show them and say why the rest is missing.
            $this->vmError = $exception->getMessage();
        } catch (Throwable $exception) {
            report($exception);
            $this->vmError = trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.unexpected');
        }
    }

    protected function fail(BridgeException $exception): void
    {
        $this->metrics = null;
        $this->error = $exception->getMessage();

        if ($exception->isBridgeMissing()) {
            $this->state = self::STATE_BRIDGE_MISSING;
        } elseif (in_array($exception->errorCode, BridgeClient::NOT_READY_STATES, true)) {
            $this->state = self::STATE_NOT_READY;
            // A paused container is server_not_running too, with container_state paused.
            $this->notReadyCode = $exception->errorCode === 'server_not_running' && ($exception->details['container_state'] ?? null) === 'paused'
                ? 'server_paused'
                : $exception->errorCode;
            $this->history = [];
            $this->vm = null;
            $this->liveHeap = null;
            $this->liveAt = null;
        } else {
            $this->state = self::STATE_ERROR;
        }
    }

    /**
     * Seconds until the next update, null when the user has to try again by hand (no bridge, an error).
     */
    public function pollSeconds(): ?int
    {
        return match ($this->state) {
            self::STATE_READY => max(2, min(5, (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.poll_interval', 3))),
            self::STATE_NOT_READY => self::NOT_READY_POLL_SECONDS,
            default => null,
        };
    }

    /**
     * @return list<array{rule: string, severity: string, title: string, body: string}>
     */
    public function findings(): array
    {
        $liveMetaspace = $this->liveHeap['metaspace'] ?? null;

        return app(Diagnostics::class)->run($this->vm, $this->metrics, $this->history, is_array($liveMetaspace) ? $liveMetaspace : null);
    }

    /**
     * Stacked heap chart: old generation at the bottom, young on top, against the maximum heap.
     *
     * @return array{old: string, young: string, max: string, young_now: string, old_now: string, span: string}|null
     */
    public function heapChart(int $width, int $height): ?array
    {
        if ($this->history === []) {
            return null;
        }

        $times = array_column($this->history, 't');
        $old = array_column($this->history, 'old');
        $total = array_map(fn (array $sample) => $sample['old'] + $sample['young'], $this->history);
        $last = $this->history[count($this->history) - 1];
        $max = (float) max(1, $last['max'], ...$total);

        return [
            'old' => SvgChart::band($times, array_fill(0, count($times), 0), $old, $max, $width, $height),
            'young' => SvgChart::band($times, $old, $total, $max, $width, $height),
            'max' => Format::bytes($last['max']),
            'young_now' => Format::bytes($last['young']),
            'old_now' => Format::bytes($last['old']),
            'span' => Format::duration(MetricsHistory::span($this->history)),
        ];
    }

    /**
     * GC pause time per minute between the readings.
     *
     * @return array{line: string, top: string, now: string, share: string}|null
     */
    public function gcChart(int $width, int $height): ?array
    {
        $rates = MetricsHistory::gcPerMinute($this->history);

        if ($rates === []) {
            return null;
        }

        $values = array_column($rates, 'value');
        // At least 1 s per minute as the top, so a few ms don't fill the chart.
        $top = max(1000.0, ...$values);
        $share = MetricsHistory::gcShare($this->history, 1);

        return [
            'line' => SvgChart::line(array_column($rates, 't'), $values, $top, $width, $height),
            'top' => Format::duration($top),
            'now' => Format::duration($values[count($values) - 1]),
            'share' => $share === null ? '–' : Format::percent($share, 2),
        ];
    }

    /**
     * The JVM at a glance.
     *
     * @return list<array{label: string, value: string, hint?: string|null}>
     */
    public function facts(): array
    {
        $vm = $this->vm ?? [];
        $metrics = $this->metrics ?? [];
        $t = fn (string $key, array $replace = []) => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.overview.'.$key, $replace);

        $javaVersion = $vm['java']['version'] ?? null;
        $vendor = $vm['java']['vm_vendor'] ?? null;

        $gcType = $vm['gc']['type'] ?? $metrics['gc']['type'] ?? null;
        $gcSelectedBy = $vm['gc']['selected_by'] ?? null;

        $xmx = $vm['memory_settings']['xmx_bytes'] ?? null;
        $heapMax = $vm['heap_max_bytes'] ?? $metrics['heap']['max_bytes'] ?? null;
        $limit = $vm['container']['memory_limit_bytes'] ?? null;

        $cpus = $vm['container']['effective_cpus'] ?? null;

        $threads = $metrics['threads'] ?? null;
        $metaspace = $metrics['metaspace'] ?? $vm['metaspace'] ?? null;

        $facts = [
            [
                'label' => $t('java'),
                'value' => $javaVersion === null ? '–' : $javaVersion.($vendor ? " ($vendor)" : ''),
            ],
            [
                'label' => $t('gc_label'),
                'value' => $gcType === null ? '–' : $t('gc_types.'.$gcType),
                'hint' => $gcSelectedBy === null ? null : $t('gc_selected_by.'.$gcSelectedBy),
            ],
            [
                'label' => $t('heap_max'),
                'value' => Format::bytes($xmx ?? $heapMax),
                'hint' => $xmx === null ? $t('no_xmx') : null,
            ],
            [
                'label' => $t('memory_limit'),
                'value' => $limit === null ? $t('unlimited') : Format::bytes($limit),
                'hint' => $limit !== null && ($xmx ?? $heapMax) !== null ? $t('heap_of_limit', ['percent' => Format::percent(($xmx ?? $heapMax) / $limit * 100, 0)]) : null,
            ],
            [
                'label' => $t('cpus'),
                'value' => Format::cpus($cpus),
                'hint' => isset($vm['container']['host_cpus']) ? $t('host_cpus', ['count' => $vm['container']['host_cpus']]) : null,
            ],
            [
                'label' => $t('uptime'),
                'value' => Format::duration($metrics['uptime_ms'] ?? $vm['launch']['uptime_ms'] ?? null),
            ],
            [
                'label' => $t('threads'),
                'value' => $threads === null ? '–' : (string) $threads['live'],
                'hint' => $threads === null ? null : $t('threads_hint', ['daemon' => $threads['daemon'], 'peak' => $threads['peak']]),
            ],
            [
                'label' => $t('metaspace'),
                'value' => Format::bytes($metaspace['used_bytes'] ?? null),
                'hint' => isset($metrics['classes']['current']) ? $t('classes', ['count' => number_format($metrics['classes']['current'])]) : null,
            ],
        ];

        // Only where it can be read: a bridge with live_heap and a JVM it may attach to.
        if ($this->showsLiveHeap()) {
            array_splice($facts, 3, 0, [[
                'label' => $t('heap_live'),
                'value' => isset($this->liveHeap['used_bytes']) ? Format::bytes($this->liveHeap['used_bytes']) : '–',
                'hint' => isset($this->liveHeap['used_bytes'])
                    ? $t('heap_live_hint', ['committed' => Format::bytes($this->liveHeap['committed_bytes'] ?? null), 'time' => Carbon::createFromTimestampMs((int) ($this->liveHeap['sampled_at'] ?? 0))->setTimezone(user()?->timezone ?? config('app.timezone'))->format('H:i:s')])
                    : $t('heap_live_waiting'),
            ]]);
        }

        return $facts;
    }

    /**
     * Whether the overview shows the live heap: not once the bridge said it can't (before its milestone 2),
     * nor for a JVM it won't attach to (-XX:+DisableAttachMechanism, -Xrs; the flags note says why).
     */
    public function showsLiveHeap(): bool
    {
        return $this->liveSupported
            && (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.live_interval', self::DEFAULT_LIVE_INTERVAL) > 0
            && !in_array($this->vm['attach']['reason'] ?? null, ['disabled', 'xrs'], true);
    }

    /**
     * Why VM.flags are missing, null when they are there or the bridge can't read them at all yet (milestone 1).
     */
    public function flagsNote(): ?string
    {
        if ($this->vm === null || ($this->vm['flags'] ?? null) !== null) {
            return null;
        }

        $error = $this->vm['flags_error'] ?? null;
        $t = fn (string $key) => trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.overview.flags.'.$key);

        return match (true) {
            $error === null, $error === 'not_implemented' => null,
            $error === 'attach_unavailable' => $t(in_array($this->vm['attach']['reason'] ?? null, ['disabled', 'xrs', 'jvm_starting'], true) ? $this->vm['attach']['reason'] : 'unknown'),
            default => $t('failed'),
        };
    }

    /**
     * JVM arguments as the JVM got them, e.g. -Xms128M -Xmx1907M.
     */
    public function vmArgs(): ?string
    {
        $args = $this->vm['launch']['vm_args'] ?? null;

        return is_string($args) && $args !== '' ? $args : null;
    }

    public function isFake(): bool
    {
        return $this->bridge()->isFake();
    }

    /**
     * The tab MPA Bridge of the node in the admin area, for users who may change the node.
     */
    public function nodeSettingsUrl(): ?string
    {
        $node = $this->getServer()->node;

        if (!(user()?->can('update', $node) ?? false)) {
            return null;
        }

        try {
            return EditNode::getUrl(['record' => $node, 'tab' => NodeBridgeTab::TAB_ID], panel: 'admin');
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function bridgeAddress(): string
    {
        return $this->bridge()->baseUrl($this->getServer()->node);
    }

    public function render(): View
    {
        return view('minecraft-performance-analyser::livewire.overview');
    }
}
