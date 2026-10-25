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
 * MPA_FAKE_BRIDGE=true plays a bridge with every feature. m1 or m2 play a bridge of that milestone: its
 * node status (node-status-m1.json, node-status-m2.json), and 501 not_implemented for what it can't do
 * yet (m1: threads, histogram, profiles; m2: profiles).
 *
 * The examples are docs/examples of the bridge repo at commit 20f2692 (milestone 2 of the bridge),
 * copied unchanged. The answers carry the User-Agent of the bridge with the node's token id, so they pass
 * the same checks as real ones.
 */
class FakeBridge
{
    /** bridge_version of node-status.json. */
    public const VERSION = '0.1.0';

    /** Milestone of the bridge with every feature the plugin uses. */
    public const ALL = 3;

    /**
     * Milestone of the bridge to play, from the config value: true (or anything else) all, m1 / m2 that one.
     */
    public static function milestone(mixed $setting): int
    {
        return match (is_string($setting) ? strtolower(trim($setting)) : $setting) {
            'm1', '1' => 1,
            'm2', '2' => 2,
            default => self::ALL,
        };
    }

    public function respond(Request $request, Node $node, int $milestone = self::ALL): PromiseInterface
    {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        [$file, $status] = static::route($request->method(), (string) parse_url($url, PHP_URL_PATH), $query, $milestone);

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'MPA Bridge/v'.self::VERSION.' (id:'.$node->daemon_token_id.')',
        ];

        return Factory::response($file === null ? '' : (string) $this->disk()->get($file), $status, $headers);
    }

    /**
     * Example file and HTTP status for a request, like a bridge of that milestone would answer it.
     *
     * @param  array<mixed>  $query
     * @return array{0: string|null, 1: int} file name (null for an empty body) and status
     */
    public static function route(string $method, string $path, array $query = [], int $milestone = self::ALL): array
    {
        $method = strtoupper($method);
        $path = '/'.trim($path, '/');

        if ($path === '/api/v1/status') {
            if ($method !== 'GET') {
                return ['error-method-not-allowed.json', 405];
            }

            return [match ($milestone) {
                1 => 'node-status-m1.json',
                2 => 'node-status-m2.json',
                default => 'node-status.json',
            }, 200];
        }

        if (!preg_match('#^/api/v1/servers/[0-9a-fA-F-]{36}/(.+)$#', $path, $matches)) {
            return ['error-not-found.json', 404];
        }

        $endpoint = $matches[1];
        $isProfile = (bool) preg_match('#^profiles(/[0-9a-f]{16})?$#', $endpoint);
        $notImplemented = ['error-not-implemented.json', 501];

        if (($milestone < 3 && $isProfile) || ($milestone < 2 && in_array($endpoint, ['threads', 'histogram'], true))) {
            return $notImplemented;
        }

        return match (true) {
            $endpoint === 'status' && $method === 'GET' => ['status.json', 200],
            // VM.flags come with milestone 2; the example without them is a JVM that is just starting.
            $endpoint === 'vm' && $method === 'GET' => [$milestone < 2 ? 'vm-without-flags.json' : 'vm.json', 200],
            $endpoint === 'metrics' && $method === 'GET' => [($query['live'] ?? null) === '1' && $milestone >= 2 ? 'metrics-live.json' : 'metrics.json', 200],
            $endpoint === 'threads' && $method === 'GET' => ['threads.json', 200],
            $endpoint === 'histogram' && $method === 'GET' => ['histogram.json', 200],
            $endpoint === 'profiles' && $method === 'POST' => ['profile-created.json', 202],
            $endpoint === 'profiles' && $method === 'GET' => ['profiles.json', 200],
            $isProfile && $method === 'GET' => ['profile-done.json', 200],
            $isProfile && $method === 'DELETE' => [null, 204],
            $isProfile || in_array($endpoint, ['status', 'vm', 'metrics', 'threads', 'histogram'], true) => ['error-method-not-allowed.json', 405],
            default => ['error-not-found.json', 404],
        };
    }

    protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::build(dirname(__DIR__, 2).'/resources/fake-bridge');
    }
}
