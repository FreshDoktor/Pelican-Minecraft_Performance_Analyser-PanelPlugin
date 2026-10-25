<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\MetricsHistory;

/**
 * Hints on the JVM of a server from /vm and /metrics of the bridge (docs/API.md, contract of wing
 * commit 49f32b3). Plain PHP apart from trans(), so it is tested without Laravel (tests/Unit).
 *
 * Rules (PLAN.md section 3) that need more than /vm and /metrics are not here yet: whether the Java
 * version suits the Minecraft version needs the Minecraft version of the server.
 *
 * @phpstan-import-type Sample from MetricsHistory
 *
 * @phpstan-type Finding array{rule: string, severity: string, title: string, body: string}
 */
class Diagnostics
{
    public const SEVERITY_DANGER = 'danger';

    public const SEVERITY_WARNING = 'warning';

    /** Code cache, thread stacks and Netty buffers the JVM needs besides heap and metaspace. */
    public const OVERHEAD_BYTES = 300 * Format::MIB;

    /** The JVM picks the Serial GC by itself below 2 CPUs or below 1792 MiB of memory. */
    public const SERIAL_MIN_CPUS = 2;

    public const SERIAL_MIN_MEMORY_BYTES = 1792 * Format::MIB;

    /** Without -Xmx and MaxRAMPercentage the JVM takes 25 % of the container's memory. */
    public const DEFAULT_RAM_PERCENTAGE = 25;

    public const GC_SHARE_LIMIT_PERCENT = 5.0;

    /** The GC share is only judged over at least this much history. */
    public const GC_SHARE_MIN_SPAN_MS = 60000;

    public const OLD_GEN_LIMIT_PERCENT = 90.0;

    protected const TRANSLATION_PREFIX = 'minecraft-performance-analyser::strings.diagnostics.';

    /**
     * Every rule that applies, the most serious first.
     *
     * @param  array<string, mixed>|null  $vm  answer of GET /vm
     * @param  array<string, mixed>|null  $metrics  answer of GET /metrics
     * @param  list<Sample>  $history  samples of the last minutes, see MetricsHistory
     * @param  array<string, mixed>|null  $liveMetaspace  live_heap.metaspace of GET /metrics?live=1, for a JVM that has not collected garbage yet
     * @return list<Finding>
     */
    public function run(?array $vm, ?array $metrics, array $history = [], ?array $liveMetaspace = null): array
    {
        $findings = array_values(array_filter([
            $vm !== null ? $this->xmxNearLimit($vm, $liveMetaspace) : null,
            $metrics !== null ? $this->oldGenerationFull($metrics) : null,
            $this->highGcShare($history),
            $vm !== null ? $this->serialGc($vm) : null,
            $vm !== null ? $this->noXmx($vm) : null,
        ]));

        usort($findings, fn (array $a, array $b) => ($a['severity'] === self::SEVERITY_DANGER ? 0 : 1) <=> ($b['severity'] === self::SEVERITY_DANGER ? 0 : 1));

        return $findings;
    }

    /**
     * @param  array<string, mixed>  $vm
     * @return Finding|null
     */
    public function serialGc(array $vm): ?array
    {
        if (($vm['gc']['type'] ?? null) !== 'serial') {
            return null;
        }

        if (($vm['gc']['selected_by'] ?? null) === 'flag') {
            return $this->finding('serial_gc', self::SEVERITY_WARNING, 'body_flag');
        }

        $cpus = $vm['container']['effective_cpus'] ?? null;
        $limit = $vm['container']['memory_limit_bytes'] ?? null;

        $fewCpus = $cpus !== null && $cpus < self::SERIAL_MIN_CPUS;
        $littleMemory = $limit !== null && $limit < self::SERIAL_MIN_MEMORY_BYTES;

        $reason = match (true) {
            $fewCpus && $littleMemory => 'body_cpu_memory',
            $fewCpus => 'body_cpu',
            $littleMemory => 'body_memory',
            default => 'body_unknown',
        };

        return $this->finding('serial_gc', self::SEVERITY_WARNING, $reason, [
            'cpus' => Format::cpus($cpus),
            'memory' => Format::bytes($limit),
        ]);
    }

    /**
     * @param  array<string, mixed>  $vm
     * @return Finding|null
     */
    public function noXmx(array $vm): ?array
    {
        $settings = $vm['memory_settings'] ?? [];

        if (($settings['xmx_bytes'] ?? null) !== null || ($settings['max_ram_percentage'] ?? null) !== null) {
            return null;
        }

        $limit = $vm['container']['memory_limit_bytes'] ?? null;

        return $this->finding('no_xmx', self::SEVERITY_WARNING, $limit === null ? 'body_unlimited' : 'body', [
            'percent' => self::DEFAULT_RAM_PERCENTAGE,
            'heap' => Format::bytes($vm['heap_max_bytes'] ?? null),
            'limit' => Format::bytes($limit),
        ]);
    }

    /**
     * Heap + metaspace + overhead above the memory limit of the container: once the heap is used up, the
     * kernel kills the container (OOM) instead of the JVM collecting garbage.
     *
     * @param  array<string, mixed>  $vm
     * @param  array<string, mixed>|null  $liveMetaspace  live_heap.metaspace, see run()
     * @return Finding|null
     */
    public function xmxNearLimit(array $vm, ?array $liveMetaspace = null): ?array
    {
        $limit = $vm['container']['memory_limit_bytes'] ?? null;
        $heap = $this->maxHeap($vm);

        if ($limit === null || $heap === null) {
            return null;
        }

        $metaspace = (int) max($vm['metaspace']['committed_bytes'] ?? 0, $vm['metaspace']['used_bytes'] ?? 0);

        // hsperfdata reports 0 until the first GC, the live value is right from the start.
        if ($metaspace === 0 && $liveMetaspace !== null) {
            $metaspace = (int) max($liveMetaspace['committed_bytes'] ?? 0, $liveMetaspace['used_bytes'] ?? 0);
        }

        $total = $heap + $metaspace + self::OVERHEAD_BYTES;

        if ($total <= $limit) {
            return null;
        }

        $suggested = intdiv(max(0, $limit - $metaspace - self::OVERHEAD_BYTES), Format::MIB);

        return $this->finding('xmx_near_limit', self::SEVERITY_DANGER, 'body', [
            'xmx' => Format::bytes($heap),
            'metaspace' => Format::bytes($metaspace),
            'overhead' => Format::bytes(self::OVERHEAD_BYTES),
            'total' => Format::bytes($total),
            'limit' => Format::bytes($limit),
            'suggested' => $suggested.'M',
        ]);
    }

    /**
     * @param  list<Sample>  $history
     * @return Finding|null
     */
    public function highGcShare(array $history): ?array
    {
        $share = MetricsHistory::gcShare($history, self::GC_SHARE_MIN_SPAN_MS);

        if ($share === null || $share <= self::GC_SHARE_LIMIT_PERCENT) {
            return null;
        }

        return $this->finding('high_gc_share', self::SEVERITY_WARNING, 'body', [
            'percent' => Format::percent($share),
            'limit' => Format::percent(self::GC_SHARE_LIMIT_PERCENT, 0),
            'minutes' => max(1, (int) round(MetricsHistory::span($history) / 60000)),
        ]);
    }

    /**
     * The old generation after the last GC, against its maximum and never against what the JVM
     * reserved so far: it grows the generation only when needed.
     *
     * @param  array<string, mixed>  $metrics
     * @return Finding|null
     */
    public function oldGenerationFull(array $metrics): ?array
    {
        $used = 0;
        $max = 0;

        foreach ($metrics['generations'] ?? [] as $generation) {
            if (($generation['kind'] ?? null) === 'old') {
                $used += (int) ($generation['used_bytes'] ?? 0);
                $max += (int) ($generation['max_bytes'] ?? 0);
            }
        }

        if ($max <= 0) {
            return null;
        }

        $percent = $used / $max * 100;

        if ($percent <= self::OLD_GEN_LIMIT_PERCENT) {
            return null;
        }

        return $this->finding('old_gen_full', self::SEVERITY_DANGER, 'body', [
            'used' => Format::bytes($used),
            'max' => Format::bytes($max),
            'percent' => Format::percent($percent),
        ]);
    }

    /**
     * The heap the JVM may grow to: -Xmx, else MaxRAMPercentage of the limit, else what the JVM reports.
     *
     * @param  array<string, mixed>  $vm
     */
    protected function maxHeap(array $vm): ?int
    {
        $settings = $vm['memory_settings'] ?? [];
        $limit = $vm['container']['memory_limit_bytes'] ?? null;

        if (($settings['xmx_bytes'] ?? null) !== null) {
            return (int) $settings['xmx_bytes'];
        }

        if (($settings['max_ram_percentage'] ?? null) !== null && $limit !== null) {
            return (int) ($limit * $settings['max_ram_percentage'] / 100);
        }

        return isset($vm['heap_max_bytes']) ? (int) $vm['heap_max_bytes'] : null;
    }

    /**
     * @param  array<string, scalar>  $replace
     * @return Finding
     */
    protected function finding(string $rule, string $severity, string $body, array $replace = []): array
    {
        return [
            'rule' => $rule,
            'severity' => $severity,
            'title' => (string) trans(self::TRANSLATION_PREFIX.$rule.'.title', $replace),
            'body' => (string) trans(self::TRANSLATION_PREFIX.$rule.'.'.$body, $replace),
        ];
    }
}
