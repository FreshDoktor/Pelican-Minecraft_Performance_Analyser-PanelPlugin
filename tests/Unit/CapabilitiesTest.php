<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\FakeBridge;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Capabilities as C;
use PHPUnit\Framework\TestCase;

/**
 * What the page shows, hides or disables, from the example answers of GET /status.
 */
class CapabilitiesTest extends TestCase
{
    protected const ACTIONS = [C::PROFILE, C::HISTOGRAM, C::THREADS, C::LIVE_HEAP, C::TICKS];

    public function test_a_ready_server_can_do_everything(): void
    {
        $capabilities = C::fromStatus(self::ready());

        foreach (self::ACTIONS as $action) {
            $this->assertTrue($capabilities->available($action), $action);
            $this->assertFalse($capabilities->hidden($action), $action);
            $this->assertFalse($capabilities->disabled($action), $action);
        }
    }

    public function test_without_bridge_everything_is_hidden(): void
    {
        foreach ([true => C::BRIDGE_MISSING, false => C::BRIDGE_ERROR] as $missing => $reason) {
            $capabilities = C::bridgeFailed((bool) $missing);

            foreach (self::ACTIONS as $action) {
                $this->assertSame($reason, $capabilities->reason($action));
                $this->assertTrue($capabilities->hidden($action));
            }
        }
    }

    public function test_a_bridge_of_an_older_milestone_hides_what_it_cant_do(): void
    {
        $m1 = self::ready(features: FakeBridge::FEATURES[1]);
        $m2 = self::ready(features: FakeBridge::FEATURES[2]);

        foreach (self::ACTIONS as $action) {
            $this->assertSame(C::NOT_IMPLEMENTED, C::fromStatus($m1)->reason($action), $action);
            $this->assertTrue(C::fromStatus($m1)->hidden($action), $action);
        }

        $this->assertSame(C::NOT_IMPLEMENTED, C::fromStatus($m2)->reason(C::PROFILE));
        $this->assertTrue(C::fromStatus($m2)->available(C::THREADS));
        $this->assertTrue(C::fromStatus($m2)->available(C::HISTOGRAM));

        // A bridge 0.1.0: profiles, but no tick monitor.
        $m3 = C::fromStatus(self::ready(features: FakeBridge::FEATURES[3]));
        $this->assertTrue($m3->available(C::PROFILE));
        $this->assertSame(C::NOT_IMPLEMENTED, $m3->reason(C::TICKS));
        $this->assertTrue($m3->hidden(C::TICKS));
    }

    public function test_a_stopped_or_starting_server_disables(): void
    {
        $paused = DiagnosticsTest::example('status-not-running');
        $paused['server']['container_state'] = 'paused';

        $cases = [
            'server_not_running' => DiagnosticsTest::example('status-not-running'),
            'server_paused' => $paused,
            'no_java_process' => DiagnosticsTest::example('status-no-java'),
            'hsperf_unavailable' => DiagnosticsTest::example('status-hsperf-unavailable'),
        ];

        foreach ($cases as $reason => $status) {
            $capabilities = C::fromStatus($status);

            foreach (self::ACTIONS as $action) {
                $this->assertSame($reason, $capabilities->reason($action), "$reason: $action");
                $this->assertTrue($capabilities->disabled($action), "$reason: $action");
                $this->assertFalse($capabilities->hidden($action), "$reason: $action");
            }
        }
    }

    public function test_attach_reasons_disable_with_the_reason(): void
    {
        foreach (['disabled', 'xrs', 'jvm_starting', 'unknown'] as $reason) {
            $capabilities = C::fromStatus(self::ready(attach: ['supported' => $reason !== 'disabled', 'listener_running' => false, 'available' => false, 'reason' => $reason]));

            $this->assertSame('attach_'.$reason, $capabilities->reason(C::THREADS));
            $this->assertTrue($capabilities->disabled(C::PROFILE));
        }

        // A reason of a newer bridge counts as unknown.
        $newer = C::fromStatus(self::ready(attach: ['supported' => true, 'listener_running' => false, 'available' => false, 'reason' => 'cgroup_frozen']));
        $this->assertSame('attach_unknown', $newer->reason(C::HISTOGRAM));
    }

    public function test_a_proxy_has_no_lag_analysis_but_threads(): void
    {
        $capabilities = C::fromStatus(self::ready(), proxy: true);

        $this->assertSame(C::NO_SERVER_THREAD, $capabilities->reason(C::PROFILE));
        $this->assertTrue($capabilities->hidden(C::PROFILE));
        $this->assertSame(C::NO_SERVER_THREAD, $capabilities->reason(C::TICKS));
        $this->assertTrue($capabilities->available(C::THREADS));
        $this->assertTrue($capabilities->available(C::HISTOGRAM));
        $this->assertTrue($capabilities->serverThreadMissing());

        // Hidden beats disabled: a stopped proxy won't get a lag analysis by starting it.
        $stopped = C::fromStatus(DiagnosticsTest::example('status-not-running'), proxy: true);
        $this->assertTrue($stopped->hidden(C::PROFILE));
        $this->assertTrue($stopped->disabled(C::THREADS));
    }

    public function test_a_profile_of_this_jvm_without_server_thread_hides_the_lag_analysis(): void
    {
        $status = self::ready();
        $jvmStarted = $status['java']['started_at'];
        $profile = fn (int $startedAt, array $warnings) => ['started_at' => $startedAt, 'result' => ['warnings' => $warnings]];

        $this->assertTrue(C::fromStatus($status, lastProfile: $profile($jvmStarted + 60000, [C::SERVER_THREAD_MISSING]))->hidden(C::PROFILE));
        $this->assertTrue(C::fromStatus($status, lastProfile: $profile($jvmStarted + 60000, [C::SERVER_THREAD_MISSING]))->hidden(C::TICKS));
        // Of an earlier JVM: says nothing, the egg may have changed.
        $this->assertTrue(C::fromStatus($status, lastProfile: $profile($jvmStarted - 60000, [C::SERVER_THREAD_MISSING]))->available(C::PROFILE));
        $this->assertTrue(C::fromStatus($status, lastProfile: $profile($jvmStarted + 60000, ['no_samples']))->available(C::PROFILE));
        $this->assertTrue(C::fromStatus($status, lastProfile: ['started_at' => $jvmStarted + 60000, 'result' => null])->available(C::PROFILE));
    }

    public function test_the_start_of_the_jvm(): void
    {
        $this->assertSame(self::ready()['java']['started_at'], C::fromStatus(self::ready())->jvmStartedAt());
        $this->assertNull(C::fromStatus(DiagnosticsTest::example('status-not-running'))->jvmStartedAt());
        $this->assertNull(C::bridgeFailed(true)->jvmStartedAt());
    }

    public function test_every_reason_has_a_text_in_both_languages(): void
    {
        foreach (['en', 'de'] as $locale) {
            $strings = require dirname(__DIR__, 2)."/lang/$locale/strings.php";

            foreach (C::REASONS as $reason) {
                $this->assertArrayHasKey($reason, $strings['capabilities'], "$locale: capabilities.$reason");
            }
        }
    }

    /**
     * status.json (ready), with other features or another attach object.
     *
     * @param  list<string>|null  $features
     * @param  array<string, mixed>|null  $attach
     * @return array<string, mixed>
     */
    protected static function ready(?array $features = null, ?array $attach = null): array
    {
        $status = DiagnosticsTest::example('status');

        if ($features !== null) {
            $status['bridge']['features'] = $features;
        }

        if ($attach !== null) {
            $status['attach'] = $attach;
        }

        return $status;
    }
}
