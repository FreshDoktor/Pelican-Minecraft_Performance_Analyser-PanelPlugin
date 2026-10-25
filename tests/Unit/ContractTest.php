<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Services\FakeBridge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The plugin's side of the API contract: the fake mode routes like the bridge, every error code of the
 * contract has a text in both languages, and both languages have the same keys.
 */
class ContractTest extends TestCase
{
    protected const UUID = '1a7ce997-259b-452e-8b4e-cecc464142ca';

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string|null, 4: int}>
     */
    public static function routes(): array
    {
        $server = '/api/v1/servers/'.self::UUID;

        return [
            'node status' => ['GET', '/api/v1/status', [], 'node-status.json', 200],
            'server status' => ['GET', "$server/status", [], 'status.json', 200],
            'vm' => ['GET', "$server/vm", [], 'vm.json', 200],
            'metrics' => ['GET', "$server/metrics", [], 'metrics.json', 200],
            'live metrics' => ['GET', "$server/metrics", ['live' => '1'], 'metrics-live.json', 200],
            'threads' => ['GET', "$server/threads", [], 'threads.json', 200],
            'histogram' => ['GET', "$server/histogram", ['limit' => '100'], 'histogram.json', 200],
            'start profile' => ['POST', "$server/profiles", [], 'profile-created.json', 202],
            'profiles' => ['GET', "$server/profiles", [], 'profiles-empty.json', 200],
            'profile' => ['GET', "$server/profiles/c0f3e1a2b4d59687", [], 'profile-done.json', 200],
            'delete profile' => ['DELETE', "$server/profiles/c0f3e1a2b4d59687", [], null, 204],
            'ticks' => ['GET', "$server/ticks", [], 'ticks.json', 200],
            'ticks, wrong method' => ['POST', "$server/ticks", [], 'error-method-not-allowed.json', 405],
            'wrong method' => ['POST', "$server/vm", [], 'error-method-not-allowed.json', 405],
            'unknown path' => ['GET', '/api/v2/status', [], 'error-not-found.json', 404],
        ];
    }

    /**
     * @param  array<string, string>  $query
     */
    #[DataProvider('routes')]
    public function test_fake_bridge_routes_like_the_bridge(string $method, string $path, array $query, ?string $file, int $status): void
    {
        $this->assertSame([$file, $status], FakeBridge::route($method, $path, $query));

        if ($file !== null) {
            $this->assertFileExists(self::fakeBridgeDir().'/'.$file);
        }
    }

    public function test_fake_bridge_plays_older_milestones(): void
    {
        $server = '/api/v1/servers/'.self::UUID;

        $this->assertSame(FakeBridge::ALL, FakeBridge::milestone(true));
        $this->assertSame(FakeBridge::ALL, FakeBridge::milestone('true'));
        $this->assertSame(1, FakeBridge::milestone('m1'));
        $this->assertSame(2, FakeBridge::milestone('M2'));
        $this->assertSame(3, FakeBridge::milestone('m3'));

        // Milestone 1: status, vm, metrics only.
        $this->assertSame(['node-status-m1.json', 200], FakeBridge::route('GET', '/api/v1/status', [], 1));
        $this->assertSame(['vm-without-flags.json', 200], FakeBridge::route('GET', "$server/vm", [], 1));
        $this->assertSame(['metrics.json', 200], FakeBridge::route('GET', "$server/metrics", ['live' => '1'], 1));
        $this->assertSame(['error-not-implemented.json', 501], FakeBridge::route('GET', "$server/threads", [], 1));
        $this->assertSame(['error-not-implemented.json', 501], FakeBridge::route('POST', "$server/profiles", [], 1));

        // Milestone 2: attach, but no profiles.
        $this->assertSame(['node-status-m2.json', 200], FakeBridge::route('GET', '/api/v1/status', [], 2));
        $this->assertSame(['metrics-live.json', 200], FakeBridge::route('GET', "$server/metrics", ['live' => '1'], 2));
        $this->assertSame(['histogram.json', 200], FakeBridge::route('GET', "$server/histogram", [], 2));
        $this->assertSame(['error-not-implemented.json', 501], FakeBridge::route('GET', "$server/profiles/c0f3e1a2b4d59687", [], 2));
        $this->assertSame(['error-not-implemented.json', 501], FakeBridge::route('GET', "$server/ticks", [], 2));

        // Milestone 3 (bridge 0.1.0): profiles, but no tick monitor.
        $this->assertSame(['node-status-m3.json', 200], FakeBridge::route('GET', '/api/v1/status', [], 3));
        $this->assertSame(['profile-created.json', 202], FakeBridge::route('POST', "$server/profiles", [], 3));
        $this->assertSame(['error-not-implemented.json', 501], FakeBridge::route('GET', "$server/ticks", [], 3));

        foreach (['node-status-m1.json', 'node-status-m2.json', 'node-status-m3.json'] as $file) {
            $this->assertFileExists(self::fakeBridgeDir().'/'.$file);
        }
    }

    public function test_fake_ticks_play_every_state(): void
    {
        $ticks = '/api/v1/servers/'.self::UUID.'/ticks';

        foreach (FakeBridge::TICKS_EXAMPLES as $example) {
            [$file, $status] = FakeBridge::route('GET', $ticks, [], FakeBridge::ALL, strtoupper($example));

            $this->assertSame(["ticks-$example.json", 200], [$file, $status]);
            $this->assertFileExists(self::fakeBridgeDir().'/'.$file);
        }

        $this->assertSame(['ticks.json', 200], FakeBridge::route('GET', $ticks, [], FakeBridge::ALL, ''));
        $this->assertSame(['ticks.json', 200], FakeBridge::route('GET', $ticks, [], FakeBridge::ALL, '../status'));
    }

    public function test_fake_server_status_has_the_features_of_the_milestone(): void
    {
        $status = (string) file_get_contents(self::fakeBridgeDir().'/status.json');

        foreach (FakeBridge::FEATURES as $milestone => $features) {
            $node = json_decode((string) file_get_contents(self::fakeBridgeDir()."/node-status-m$milestone.json"), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($node['features'], $features);

            $patched = json_decode(FakeBridge::body('status.json', $status, $milestone), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($features, $patched['bridge']['features']);
        }

        $this->assertSame($status, FakeBridge::body('status.json', $status, FakeBridge::ALL));
        $this->assertSame('{}', FakeBridge::body('vm.json', '{}', 1));
    }

    public function test_every_error_code_has_a_text_in_both_languages(): void
    {
        $codes = [];

        foreach (glob(self::fakeBridgeDir().'/error-*.json') ?: [] as $file) {
            $codes[] = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['error'];
        }

        // 22 codes in the contract (67a8f92), one example each.
        $this->assertCount(22, $codes);

        foreach (['en', 'de'] as $locale) {
            $texts = self::strings($locale)['errors']['bridge'];

            foreach ($codes as $code) {
                $this->assertArrayHasKey($code, $texts, "$locale: errors.bridge.$code");
            }

            foreach (['disabled', 'xrs', 'jvm_starting', 'unknown'] as $reason) {
                $this->assertArrayHasKey($reason, self::strings($locale)['errors']['attach_reasons']);
            }
        }
    }

    public function test_both_languages_have_the_same_keys(): void
    {
        $this->assertSame(self::keys(self::strings('en')), self::keys(self::strings('de')));
        $this->assertSame(self::keys(self::activity('en')), self::keys(self::activity('de')));
    }

    protected static function fakeBridgeDir(): string
    {
        return dirname(__DIR__, 2).'/resources/fake-bridge';
    }

    /**
     * @return array<string, mixed>
     */
    protected static function strings(string $locale): array
    {
        return require dirname(__DIR__, 2)."/lang/$locale/strings.php";
    }

    /**
     * @return array<string, mixed>
     */
    protected static function activity(string $locale): array
    {
        return require dirname(__DIR__, 2)."/resources/activity-lang/$locale/activity.php";
    }

    /**
     * @param  array<mixed>  $array
     * @return list<string>
     */
    protected static function keys(array $array, string $prefix = ''): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $keys[] = $prefix.$key;

            if (is_array($value)) {
                array_push($keys, ...self::keys($value, $prefix.$key.'.'));
            }
        }

        sort($keys);

        return $keys;
    }
}
