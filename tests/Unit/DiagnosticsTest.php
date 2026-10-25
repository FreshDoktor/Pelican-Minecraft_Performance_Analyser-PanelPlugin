<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\Diagnostics;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rules against the example answers of the bridge (resources/fake-bridge, wing commit 20f2692), which
 * come from the test server: Java 25, Serial GC chosen for 1 CPU, -Xmx1907M in a container of 2.14 GiB.
 */
class DiagnosticsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public static function example(string $name): array
    {
        $json = file_get_contents(dirname(__DIR__, 2).'/resources/fake-bridge/'.$name.'.json');

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{rule: string}>  $findings
     * @return list<string>
     */
    protected static function rules(array $findings): array
    {
        return array_column($findings, 'rule');
    }

    /**
     * @param  list<array{rule: string, severity: string, title: string, body: string}>  $findings
     * @return array{rule: string, severity: string, title: string, body: string}
     */
    protected static function finding(array $findings, string $rule): array
    {
        foreach ($findings as $finding) {
            if ($finding['rule'] === $rule) {
                return $finding;
            }
        }

        self::fail("No finding $rule");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function vmExamples(): array
    {
        return [
            'milestone 1, without VM.flags' => ['vm-without-flags'],
            'milestone 2, with VM.flags' => ['vm'],
        ];
    }

    #[DataProvider('vmExamples')]
    public function test_the_test_server_gets_serial_gc_and_xmx_too_close_to_the_limit(string $vm): void
    {
        $findings = (new Diagnostics)->run(self::example($vm), self::example('metrics'));

        // The most serious first: an OOM kill beats lag spikes.
        $this->assertSame(['xmx_near_limit', 'serial_gc'], self::rules($findings));
    }

    public function test_serial_gc_names_the_cpu_limit_as_the_reason(): void
    {
        $finding = self::finding((new Diagnostics)->run(self::example('vm-without-flags'), null), 'serial_gc');

        $this->assertSame(Diagnostics::SEVERITY_WARNING, $finding['severity']);
        // 1 CPU, but 2.14 GiB are above the 1792 MiB of the JVM's rule: the CPU limit made the JVM choose Serial.
        $this->assertStringStartsWith('minecraft-performance-analyser::strings.diagnostics.serial_gc.body_cpu ', $finding['body']);
        $this->assertStringContainsString('"cpus":"1"', $finding['body']);
    }

    public function test_xmx_near_limit_adds_up_heap_metaspace_and_overhead(): void
    {
        $finding = self::finding((new Diagnostics)->run(self::example('vm-without-flags'), null), 'xmx_near_limit');

        $this->assertSame(Diagnostics::SEVERITY_DANGER, $finding['severity']);

        // 1999634432 (Xmx 1907 MiB) + 116064256 (metaspace committed, 111 MiB) + 300 MiB = 2429271488 > 2299579597
        $this->assertStringContainsString('"xmx":"1.86 GiB"', $finding['body']);
        $this->assertStringContainsString('"metaspace":"111 MiB"', $finding['body']);
        $this->assertStringContainsString('"overhead":"300 MiB"', $finding['body']);
        $this->assertStringContainsString('"total":"2.26 GiB"', $finding['body']);
        $this->assertStringContainsString('"limit":"2.14 GiB"', $finding['body']);
        // (2299579597 - 116064256 - 314572800) / 1 MiB = 1782 MiB
        $this->assertStringContainsString('"suggested":"1782M"', $finding['body']);
    }

    public function test_before_the_first_gc_the_live_metaspace_counts(): void
    {
        // hsperfdata says 0 until the first GC.
        $vm = self::example('vm-without-flags');
        $vm['metaspace'] = ['used_bytes' => 0, 'committed_bytes' => 0, 'max_bytes' => 1207959552, 'source' => 'after_gc'];

        $live = self::example('metrics-live')['live_heap']['metaspace'];
        $this->assertSame('live', $live['source']);

        $without = (new Diagnostics)->xmxNearLimit($vm);
        $with = (new Diagnostics)->xmxNearLimit($vm, $live);

        // 1907 MiB + 0 + 300 MiB is above the limit anyway, but only the live value gives the right total.
        $this->assertStringContainsString('"metaspace":"0 KiB"', $without['body'] ?? '');
        $this->assertStringContainsString('"metaspace":"111 MiB"', $with['body'] ?? '');
    }

    public function test_the_starting_jvm_of_the_example_still_gets_its_findings(): void
    {
        // vm-without-flags.json is a JVM of 12 s without VM.flags (attach_unavailable, jvm_starting).
        $vm = self::example('vm-without-flags');
        $this->assertNull($vm['flags']);
        $this->assertSame('jvm_starting', $vm['attach']['reason']);

        $this->assertSame(['xmx_near_limit', 'serial_gc'], self::rules((new Diagnostics)->run($vm, self::example('metrics'))));
    }

    public function test_xmx_within_the_limit_is_fine(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['memory_settings']['xmx_bytes'] = 1700 * Format::MIB;

        $this->assertNotContains('xmx_near_limit', self::rules((new Diagnostics)->run($vm, null)));
    }

    public function test_without_a_memory_limit_nothing_is_too_close(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['container']['memory_limit_bytes'] = null;

        $this->assertNull((new Diagnostics)->xmxNearLimit($vm));
    }

    public function test_max_ram_percentage_counts_as_xmx(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['memory_settings']['xmx_bytes'] = null;
        $vm['memory_settings']['max_ram_percentage'] = 95.0;

        $findings = (new Diagnostics)->run($vm, null);

        $this->assertNotContains('no_xmx', self::rules($findings));
        // 95 % of 2.14 GiB + metaspace + overhead is above the limit.
        $this->assertContains('xmx_near_limit', self::rules($findings));
    }

    public function test_no_xmx_is_reported_with_the_25_percent_of_the_jvm(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['memory_settings']['xmx_bytes'] = null;
        $vm['heap_max_bytes'] = intdiv($vm['container']['memory_limit_bytes'], 4);

        $finding = self::finding((new Diagnostics)->run($vm, null), 'no_xmx');

        $this->assertStringStartsWith('minecraft-performance-analyser::strings.diagnostics.no_xmx.body ', $finding['body']);
        $this->assertStringContainsString('"percent":25', $finding['body']);
        $this->assertStringContainsString('"heap":"548 MiB"', $finding['body']);
    }

    public function test_serial_gc_set_by_flag(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['gc']['selected_by'] = 'flag';
        $vm['memory_settings']['explicit_gc'] = 'serial';

        $finding = (new Diagnostics)->serialGc($vm);

        $this->assertNotNull($finding);
        $this->assertSame('minecraft-performance-analyser::strings.diagnostics.serial_gc.body_flag', $finding['body']);
    }

    public function test_serial_gc_for_little_memory_and_few_cpus(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['container']['memory_limit_bytes'] = 1024 * Format::MIB;

        $this->assertStringStartsWith(
            'minecraft-performance-analyser::strings.diagnostics.serial_gc.body_cpu_memory ',
            (new Diagnostics)->serialGc($vm)['body'] ?? '',
        );
    }

    public function test_g1_is_fine(): void
    {
        $vm = self::example('vm-without-flags');
        $vm['gc']['type'] = 'g1';

        $this->assertNull((new Diagnostics)->serialGc($vm));
    }

    public function test_old_generation_is_judged_against_its_maximum_not_what_is_reserved(): void
    {
        // The example: 99.98 % of the reserved 512356352 bytes, but only 38 % of the maximum.
        $this->assertNull((new Diagnostics)->oldGenerationFull(self::example('metrics')));
    }

    public function test_old_generation_full(): void
    {
        $metrics = self::example('metrics');
        $metrics['generations'][1]['used_bytes'] = (int) ($metrics['generations'][1]['max_bytes'] * 0.95);

        $finding = (new Diagnostics)->oldGenerationFull($metrics);

        $this->assertNotNull($finding);
        $this->assertSame(Diagnostics::SEVERITY_DANGER, $finding['severity']);
        $this->assertStringContainsString('"percent":"95.0 %"', $finding['body']);
    }

    public function test_high_gc_share_over_the_last_minute(): void
    {
        $history = [
            ['t' => 1_000_000, 'uptime' => 100_000, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => 1000.0],
            ['t' => 1_060_000, 'uptime' => 160_000, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => 5000.0],
        ];

        $finding = (new Diagnostics)->highGcShare($history);

        // 4000 ms in 60 s = 6.7 %
        $this->assertNotNull($finding);
        $this->assertStringContainsString('"percent":"6.7 %"', $finding['title']);
        $this->assertStringContainsString('"minutes":1', $finding['body']);
    }

    public function test_gc_share_below_five_percent_or_too_short_a_history_is_fine(): void
    {
        $low = [
            ['t' => 0, 'uptime' => 1, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => 0.0],
            ['t' => 120_000, 'uptime' => 120_001, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => 3000.0],
        ];
        $short = [
            ['t' => 0, 'uptime' => 1, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => 0.0],
            ['t' => 30_000, 'uptime' => 30_001, 'young' => 0, 'old' => 0, 'max' => 1, 'gc_pause_ms' => 10000.0],
        ];

        $this->assertNull((new Diagnostics)->highGcShare($low));
        $this->assertNull((new Diagnostics)->highGcShare($short));
        $this->assertNull((new Diagnostics)->highGcShare([]));
    }

    public function test_without_answers_there_is_nothing_to_say(): void
    {
        $this->assertSame([], (new Diagnostics)->run(null, null));
    }
}
