<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Node;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The fake mode of the BridgeClient (config fake_bridge, development only): answers every request to the
 * bridge with the examples of the API contract in resources/fake-bridge, so the page can be looked at
 * before a bridge runs on the node. Only requests of the BridgeClient are faked, through a stub on its
 * own request, the panel's other HTTP requests (Wings, Modrinth, …) go out as usual.
 *
 * MPA_FAKE_BRIDGE=true plays a bridge with every feature. m1, m2 or m3 play an older bridge: its node
 * status (node-status-m1.json …), its features in the server status, and 501 not_implemented for what it
 * can't do yet (m1: threads, histogram, profiles, ticks; m2: profiles, ticks; m3: ticks, a bridge 0.1.0).
 *
 * GET /ticks answers ticks.json, or the example MPA_FAKE_TICKS names (overloaded, paused, starting,
 * suspended, unsupported, jvm-starting), to look at the other states of the widget under the console.
 *
 * The examples are docs/examples of the bridge repo at commit b058f6d (bridge 0.3.0, the tick monitor counts the ticks
 * from its second window on), copied unchanged. The answers carry the User-Agent of the bridge with the node's token id, so they pass
 * the same checks as real ones.
 */
class FakeBridge
{
    /** bridge_version of node-status.json. */
    public const VERSION = '0.3.0';

    /** Stands for the bridge with every feature the plugin uses (0.3.0, profiles and the tick monitor). */
    public const ALL = 4;

    /** features of the older bridges, as in node-status-m1.json, -m2.json and -m3.json. */
    public const FEATURES = [
        1 => ['status', 'vm', 'metrics'],
        2 => ['status', 'vm', 'metrics', 'threads', 'histogram', 'vm_flags', 'live_heap'],
        3 => ['status', 'vm', 'metrics', 'threads', 'histogram', 'vm_flags', 'live_heap', 'profiles'],
    ];

    /** Examples of GET /ticks besides ticks.json, by the value of MPA_FAKE_TICKS. */
    public const TICKS_EXAMPLES = ['overloaded', 'paused', 'starting', 'suspended', 'unsupported', 'jvm-starting'];

    /**
     * Milestone of the bridge to play, from the config value: true (or anything else) all, m1 / m2 / m3 that one.
     */
    public static function milestone(mixed $setting): int
    {
        return match (is_string($setting) ? strtolower(trim($setting)) : $setting) {
            'm1', '1' => 1,
            'm2', '2' => 2,
            'm3', '3' => 3,
            default => self::ALL,
        };
    }

    public function respond(Request $request, Node $node, int $milestone = self::ALL, ?string $ticks = null): PromiseInterface
    {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        [$file, $status] = static::route($request->method(), (string) parse_url($url, PHP_URL_PATH), $query, $milestone, $ticks);

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'MPA Bridge/v'.self::VERSION.' (id:'.$node->daemon_token_id.')',
        ];

        $body = $file === null ? '' : static::body($file, (string) $this->disk()->get($file), $milestone);

        return Factory::response($body, $status, $headers);
    }

    /**
     * The example as a bridge of that milestone sends it: status.json lists every feature, an older bridge
     * only its own, so the page leaves out what it can't do.
     */
    public static function body(string $file, string $json, int $milestone): string
    {
        if ($file !== 'status.json' || !isset(self::FEATURES[$milestone])) {
            return $json;
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $data['bridge']['features'] = self::FEATURES[$milestone];

        return (string) json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Example file and HTTP status for a request, like a bridge of that milestone would answer it.
     *
     * @param  array<mixed>  $query
     * @param  string|null  $ticks  example of GET /ticks, one of TICKS_EXAMPLES, else ticks.json
     * @return array{0: string|null, 1: int} file name (null for an empty body) and status
     */
    public static function route(string $method, string $path, array $query = [], int $milestone = self::ALL, ?string $ticks = null): array
    {
        $method = strtoupper($method);
        $path = '/'.trim($path, '/');

        if ($path === '/api/v1/status') {
            if ($method !== 'GET') {
                return ['error-method-not-allowed.json', 405];
            }

            return [isset(self::FEATURES[$milestone]) ? "node-status-m$milestone.json" : 'node-status.json', 200];
        }

        if (!preg_match('#^/api/v1/servers/[0-9a-fA-F-]{36}/(.+)$#', $path, $matches)) {
            return ['error-not-found.json', 404];
        }

        $endpoint = $matches[1];
        $isProfile = (bool) preg_match('#^profiles(/[0-9a-f]{16})?$#', $endpoint);
        $notImplemented = ['error-not-implemented.json', 501];

        if (($milestone < 4 && $endpoint === 'ticks') || ($milestone < 3 && $isProfile) || ($milestone < 2 && in_array($endpoint, ['threads', 'histogram'], true))) {
            return $notImplemented;
        }

        $ticks = is_string($ticks) ? strtolower(trim($ticks)) : null;

        return match (true) {
            $endpoint === 'status' && $method === 'GET' => ['status.json', 200],
            // VM.flags come with milestone 2; the example without them is a JVM that is just starting.
            $endpoint === 'vm' && $method === 'GET' => [$milestone < 2 ? 'vm-without-flags.json' : 'vm.json', 200],
            $endpoint === 'metrics' && $method === 'GET' => [($query['live'] ?? null) === '1' && $milestone >= 2 ? 'metrics-live.json' : 'metrics.json', 200],
            $endpoint === 'threads' && $method === 'GET' => ['threads.json', 200],
            $endpoint === 'histogram' && $method === 'GET' => ['histogram.json', 200],
            $endpoint === 'profiles' && $method === 'POST' => ['profile-created.json', 202],
            // No profile running yet: the tab starts empty, a profile only runs once someone starts one.
            $endpoint === 'profiles' && $method === 'GET' => ['profiles-empty.json', 200],
            $isProfile && $method === 'GET' => ['profile-done.json', 200],
            $isProfile && $method === 'DELETE' => [null, 204],
            $endpoint === 'ticks' && $method === 'GET' => [in_array($ticks, self::TICKS_EXAMPLES, true) ? "ticks-$ticks.json" : 'ticks.json', 200],
            $isProfile || in_array($endpoint, ['status', 'vm', 'metrics', 'threads', 'histogram', 'ticks'], true) => ['error-method-not-allowed.json', 405],
            default => ['error-not-found.json', 404],
        };
    }

    protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::build(dirname(__DIR__, 2).'/resources/fake-bridge');
    }
}
