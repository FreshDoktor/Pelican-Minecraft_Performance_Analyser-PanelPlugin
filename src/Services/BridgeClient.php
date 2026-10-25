<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Node;
use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Models\NodeBridge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;

/**
 * Talks to the MPA Bridge on the node of a server, always from the panel, never from the browser, so
 * the token stays in the panel.
 *
 * Built like Pelican's Http::daemon() macro (AppServiceProvider) and DaemonRepository: the node's
 * daemon token as Bearer, and the answer has to carry the User-Agent "MPA Bridge/v<version>
 * (id:<token id>)" with the token id of this node, so the panel knows it talks to the bridge of the
 * right node. Unlike Wings the bridge has its own address per node (see NodeBridge), and TLS is only
 * checked when that address is https.
 *
 * The endpoints and their answers are set by docs/API.md of the bridge.
 */
class BridgeClient
{
    public const API_PREFIX = 'api/v1';

    public const DEFAULT_PORT = 8090;

    /** Like DaemonRepository::enforceValidNodeToken() with "Pelican Wings", group 1 is the token id. */
    public const USER_AGENT_PATTERN = '/^MPA Bridge\/v(?:\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-+]+)?|develop) \(id:(\w*)\)$/';

    /** @var array<int, string> base address by node id, looked up once per request */
    protected array $baseUrls = [];

    /**
     * Address of the bridge of a node: the one set in the admin area, otherwise the node's FQDN with
     * the default port.
     */
    public function baseUrl(Node $node): string
    {
        return $this->baseUrls[$node->id] ??= NodeBridge::urlFor($node) ?? $this->defaultUrl($node);
    }

    /**
     * Address of the bridge when none is set for the node. Nodes behind a proxy usually only forward the
     * Wings port, they need an address of their own.
     */
    public function defaultUrl(Node $node): string
    {
        $port = (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.default_port', self::DEFAULT_PORT);

        return "{$node->scheme}://{$node->fqdn}:".($port > 0 ? $port : self::DEFAULT_PORT);
    }

    /**
     * GET an endpoint of a server, e.g. get($server, 'status') for /api/v1/servers/<uuid>/status.
     *
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     *
     * @throws BridgeException
     */
    public function get(Server $server, string $path, array $query = [], ?int $timeout = null): array
    {
        return $this->send($server->node, 'GET', $this->serverPath($server, $path), $query === [] ? [] : ['query' => $query], $timeout);
    }

    /**
     * @param  array<string, mixed>  $data  sent as JSON
     * @return array<mixed>
     *
     * @throws BridgeException
     */
    public function post(Server $server, string $path, array $data = [], ?int $timeout = null): array
    {
        return $this->send($server->node, 'POST', $this->serverPath($server, $path), ['json' => $data], $timeout);
    }

    /**
     * @return array<mixed>
     *
     * @throws BridgeException
     */
    public function delete(Server $server, string $path, ?int $timeout = null): array
    {
        return $this->send($server->node, 'DELETE', $this->serverPath($server, $path), [], $timeout);
    }

    /**
     * Send a request to the bridge of a node and return its decoded JSON answer.
     *
     * @param  string  $path  relative to the base address, e.g. api/v1/servers/<uuid>/status
     * @param  array<string, mixed>  $options  Guzzle options (query, json)
     * @param  string|null  $baseUrl  another address than the saved one, e.g. one to test before saving it
     * @return array<mixed>
     *
     * @throws BridgeException
     */
    public function send(Node $node, string $method, string $path, array $options = [], ?int $timeout = null, ?string $baseUrl = null): array
    {
        $response = $this->request($node, $method, $path, $options, $timeout, $baseUrl);

        if ($response->failed()) {
            throw $this->toException($response);
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new BridgeException(BridgeException::INVALID_RESPONSE, $this->trans('errors.invalid_response'), $response->status());
        }

        return $data;
    }

    /**
     * Whether the bridge of a node answers, is the bridge of this node and takes its token. For the test
     * button in the admin area, where no server is at hand: an answer of the bridge for any server, even
     * "not found", carries the User-Agent and shows whether the token was taken.
     *
     * @param  string|null  $baseUrl  the address to test, null for the saved one
     * @return string|null version of the bridge
     *
     * @throws BridgeException
     */
    public function probe(Node $node, ?string $baseUrl = null): ?string
    {
        $uuid = $node->servers()->value('uuid') ?? '00000000-0000-0000-0000-000000000000';

        $response = $this->request($node, 'GET', self::API_PREFIX.'/servers/'.rawurlencode($uuid).'/status', [], null, $baseUrl);

        if ($response->status() === 401 || $response->status() === 403) {
            throw $this->toException($response);
        }

        return static::versionFromUserAgent($response->header('User-Agent'));
    }

    /**
     * Send a request and make sure the bridge of this node answered it, whatever the status.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws BridgeException
     */
    protected function request(Node $node, string $method, string $path, array $options, ?int $timeout, ?string $baseUrl): Response
    {
        $baseUrl = NodeBridge::normalize($baseUrl) ?? $this->baseUrl($node);

        try {
            $response = $this->http($node, $baseUrl, $timeout)->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            throw new BridgeException(BridgeException::UNREACHABLE, $this->trans('errors.unreachable', ['url' => $baseUrl]), previous: $exception);
        }

        // Before anything else: an error page of some other service must not pass for an error of the bridge.
        $this->verifyIdentity($response, $node, $baseUrl);

        return $response;
    }

    /**
     * Whether the answer comes from the bridge of this node, like DaemonRepository::enforceValidNodeToken()
     * does for Wings. Stricter than that: a User-Agent of another form is refused as well.
     *
     * @throws BridgeException
     */
    public function verifyIdentity(Response $response, Node $node, string $baseUrl): void
    {
        $header = $response->header('User-Agent');

        if (!preg_match(self::USER_AGENT_PATTERN, $header, $matches) || !hash_equals((string) $node->daemon_token_id, $matches[1])) {
            throw new BridgeException(BridgeException::WRONG_NODE, $this->trans('errors.wrong_node', ['url' => $baseUrl]), $response->status());
        }
    }

    /**
     * Version of the bridge from the User-Agent of an answer, e.g. 1.0.0.
     */
    public static function versionFromUserAgent(string $header): ?string
    {
        return preg_match('/^MPA Bridge\/v(\S+) /', $header, $matches) ? $matches[1] : null;
    }

    public function isFake(): bool
    {
        return (bool) config(MinecraftPerformanceAnalyserPlugin::ID.'.fake_bridge', false);
    }

    protected function http(Node $node, string $baseUrl, ?int $timeout): PendingRequest
    {
        $request = Http::acceptJson()
            ->withToken($node->daemon_token)
            ->timeout($timeout ?? (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.timeout', 10))
            ->connectTimeout((int) config(MinecraftPerformanceAnalyserPlugin::ID.'.connect_timeout', 3))
            ->baseUrl($baseUrl);

        if (str_starts_with(strtolower($baseUrl), 'https://')) {
            $request->withOptions(['verify' => (bool) config(MinecraftPerformanceAnalyserPlugin::ID.'.verify_tls', true)]);
        }

        return $request;
    }

    protected function serverPath(Server $server, string $path): string
    {
        return self::API_PREFIX.'/servers/'.rawurlencode($server->uuid).'/'.ltrim($path, '/');
    }

    /**
     * Errors of the bridge have the form {"error": "<code>", "message": "…"}. Codes the plugin knows get a
     * translated message, others keep the message of the bridge.
     */
    protected function toException(Response $response): BridgeException
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            return new BridgeException(BridgeException::UNAUTHORIZED, $this->trans('errors.unauthorized'), $status);
        }

        $data = $response->json();
        $code = is_array($data) && is_string($data['error'] ?? null) && $data['error'] !== '' ? $data['error'] : BridgeException::BRIDGE_ERROR;
        $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';

        $key = MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.bridge.'.$code;

        if (Lang::has($key)) {
            $message = trans($key);
        } elseif ($message === '') {
            $message = $this->trans('errors.bridge_error', ['status' => $status]);
        }

        return new BridgeException($code, $message, $status);
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    protected function trans(string $key, array $replace = []): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.'.$key, $replace);
    }
}
