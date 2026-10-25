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
 * The examples are docs/examples of the bridge repo at commit 06db32d ("Add API contract (milestone 0)"),
 * copied unchanged; they are the same in 30d9143. The answers carry the User-Agent of the bridge with the
 * node's token id, so they pass the same checks as real ones.
 */
class FakeBridge
{
    /** bridge_version of node-status.json. */
    public const VERSION = '0.1.0';

    public function respond(Request $request, Node $node): PromiseInterface
    {
        $url = $request->url();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        [$file, $status] = static::route($request->method(), (string) parse_url($url, PHP_URL_PATH), $query);

        $headers = [
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'MPA Bridge/v'.self::VERSION.' (id:'.$node->daemon_token_id.')',
        ];

        return Factory::response($file === null ? '' : (string) $this->disk()->get($file), $status, $headers);
    }

    /**
     * Example file and HTTP status for a request, like the real bridge would answer it.
     *
     * @param  array<mixed>  $query
     * @return array{0: string|null, 1: int} file name (null for an empty body) and status
     */
    public static function route(string $method, string $path, array $query = []): array
    {
        $method = strtoupper($method);
        $path = '/'.trim($path, '/');

        if ($path === '/api/v1/status') {
            return $method === 'GET' ? ['node-status.json', 200] : ['error-method-not-allowed.json', 405];
        }

        if (!preg_match('#^/api/v1/servers/[0-9a-fA-F-]{36}/(.+)$#', $path, $matches)) {
            return ['error-not-found.json', 404];
        }

        $endpoint = $matches[1];

        return match (true) {
            $endpoint === 'status' && $method === 'GET' => ['status.json', 200],
            // Milestone 1 of the bridge has no VM.flags yet.
            $endpoint === 'vm' && $method === 'GET' => ['vm-without-flags.json', 200],
            $endpoint === 'metrics' && $method === 'GET' => [($query['live'] ?? null) === '1' ? 'metrics-live.json' : 'metrics.json', 200],
            $endpoint === 'threads' && $method === 'GET' => ['threads.json', 200],
            $endpoint === 'histogram' && $method === 'GET' => ['histogram.json', 200],
            $endpoint === 'profiles' && $method === 'POST' => ['profile-created.json', 202],
            $endpoint === 'profiles' && $method === 'GET' => ['profiles.json', 200],
            (bool) preg_match('#^profiles/[0-9a-f]{16}$#', $endpoint) && $method === 'GET' => ['profile-done.json', 200],
            (bool) preg_match('#^profiles/[0-9a-f]{16}$#', $endpoint) && $method === 'DELETE' => [null, 204],
            in_array($endpoint, ['status', 'vm', 'metrics', 'threads', 'histogram', 'profiles'], true) => ['error-method-not-allowed.json', 405],
            default => ['error-not-found.json', 404],
        };
    }

    protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::build(dirname(__DIR__, 2).'/resources/fake-bridge');
    }
}
