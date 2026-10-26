<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Node;
use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Models\NodeBridge;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\Format;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;

/**
 * Talks to the MPA Bridge on the node of a server, always from the panel, never from the browser, so
 * the token stays in the panel.
 *
 * Built like Pelican's Http::daemon() macro (AppServiceProvider) and DaemonRepository: the node's
 * daemon token as Bearer, and every answer has to carry the User-Agent "MPA Bridge/v<version>
 * (id:<token id>)" with the token id of this node, so the panel knows it talks to the bridge of the
 * right node. Unlike Wings the bridge has its own address per node (see NodeBridge), and TLS is only
 * checked when that address is https.
 *
 * Endpoints, fields and error codes follow docs/API.md of the bridge, API v1 as of wing commit df7f20a (bridge 1.0.0, the first
 * release). Unknown fields are ignored, as the contract asks.
 */
class BridgeClient
{
    public const API_PREFIX = 'api/v1';

    public const API_VERSION = 1;

    /** Commit of docs/API.md in the bridge repo this client was written against. */
    public const CONTRACT = 'df7f20a';

    public const DEFAULT_PORT = 8090;

    /** Like DaemonRepository::enforceValidNodeToken() with "Pelican Wings", group 1 is the token id. */
    public const USER_AGENT_PATTERN = '/^MPA Bridge\/v(?:\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-+]+)?|develop) \(id:(\w*)\)$/';

    /** Values of state in GET /status besides ready, each is also the error code /vm and /metrics answer with. */
    public const NOT_READY_STATES = ['server_not_running', 'no_java_process', 'hsperf_unavailable'];

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
     * GET /api/v1/status: version, API version, features and limits of the bridge of a node. For the
     * test button and to tell whether a node has a bridge at all.
     *
     * @param  string|null  $baseUrl  the address to ask, null for the saved one
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function nodeStatus(Node $node, ?string $baseUrl = null): array
    {
        return $this->send($node, 'GET', self::API_PREFIX.'/status', baseUrl: $baseUrl);
    }

    /**
     * GET /status of a server: container state, Java process, attach. 200 as long as the container
     * exists, also when it is stopped.
     *
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function status(Server $server): array
    {
        return $this->get($server, 'status');
    }

    /**
     * GET /vm: Java, start arguments, memory settings, GC and the limits of the container.
     *
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function vm(Server $server): array
    {
        return $this->get($server, 'vm');
    }

    /**
     * GET /metrics: heap (after GC), GC, threads, classes, safepoints, cumulative since the JVM started.
     * $live adds GC.heap_info via attach (a short safepoint, milestone 2 of the bridge).
     *
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function metrics(Server $server, bool $live = false): array
    {
        return $this->get($server, 'metrics', $live ? ['live' => '1'] : []);
    }

    /**
     * POST /profiles: start a profile (async-profiler, milestone 3 of the bridge). The answer is the
     * profile object with state running.
     *
     * @param  string  $mode  wall (tick analysis), cpu or alloc
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function startProfile(Server $server, int $seconds, string $mode = 'wall'): array
    {
        return $this->post($server, 'profiles', ['seconds' => $seconds, 'mode' => $mode]);
    }

    /**
     * GET /profiles: the profiles the bridge still knows for this server, newest first, without results.
     *
     * @return list<array<string, mixed>>
     *
     * @throws BridgeException
     */
    public function profiles(Server $server): array
    {
        $profiles = $this->get($server, 'profiles')['profiles'] ?? [];

        return is_array($profiles) ? array_values(array_filter($profiles, 'is_array')) : [];
    }

    /**
     * GET /profiles/{id}: state of a profile, with the result once it is done.
     *
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function profile(Server $server, string $id): array
    {
        return $this->get($server, 'profiles/'.self::profileId($id));
    }

    /**
     * DELETE /profiles/{id}: stop a running profile, or discard a finished one on the bridge.
     *
     * @throws BridgeException
     */
    public function deleteProfile(Server $server, string $id): void
    {
        $this->delete($server, 'profiles/'.self::profileId($id));
    }

    /**
     * GET /ticks: approximate TPS and MSPT with a history of 10 minutes (feature ticks). The first call
     * starts the tick monitor of the bridge for this server, it stops by itself a minute after the last call.
     *
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function ticks(Server $server): array
    {
        return $this->get($server, 'ticks');
    }

    /**
     * Profile ids are 16 lowercase hex characters, nothing else goes into the path.
     */
    public static function profileId(string $id): string
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
            throw new BridgeException('invalid_request', trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.invalid_response'));
        }

        return $id;
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
     * @return array<mixed> empty for 204 No Content
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

        if ($response->status() === 204) {
            return [];
        }

        $data = $response->json();

        if (!is_array($data)) {
            throw new BridgeException(BridgeException::INVALID_RESPONSE, $this->trans('errors.invalid_response'), $response->status());
        }

        return $data;
    }

    /**
     * Whether the answer comes from the bridge of this node, like DaemonRepository::enforceValidNodeToken()
     * does for Wings. Stricter than that: a User-Agent of another form is refused as well. The bridge sends
     * it on every answer, errors included.
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
        $setting = config(MinecraftPerformanceAnalyserPlugin::ID.'.fake_bridge', false);

        return $setting !== null && $setting !== false && $setting !== '' && $setting !== 'false';
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
            // cURL error 28: connected, but no answer in time. The bridge is there, it is just slow (a long heap walk).
            if (str_contains($exception->getMessage(), 'cURL error 28') && !str_contains($exception->getMessage(), 'Connection timed out')) {
                throw new BridgeException(BridgeException::TIMEOUT, $this->trans('errors.timeout', ['url' => $baseUrl]), previous: $exception);
            }

            throw new BridgeException(BridgeException::UNREACHABLE, $this->trans('errors.unreachable', ['url' => $baseUrl]), previous: $exception);
        }

        // Before anything else: an error page of some other service must not pass for an error of the bridge.
        $this->verifyIdentity($response, $node, $baseUrl);

        return $response;
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

        if ($this->isFake()) {
            // Only this request is answered by the examples, see FakeBridge. stub() takes a list of callbacks.
            $fake = app(FakeBridge::class);
            $milestone = FakeBridge::milestone(config(MinecraftPerformanceAnalyserPlugin::ID.'.fake_bridge'));
            $ticks = config(MinecraftPerformanceAnalyserPlugin::ID.'.fake_ticks');
            $request->stub([fn (Request $request) => $fake->respond($request, $node, $milestone, is_string($ticks) ? $ticks : null)]);
        }

        return $request;
    }

    protected function serverPath(Server $server, string $path): string
    {
        return self::API_PREFIX.'/servers/'.rawurlencode($server->uuid).'/'.ltrim($path, '/');
    }

    /**
     * Errors of the bridge have the form {"error": "<code>", "message": "…", "details": {…}|null}. Codes
     * the plugin knows get a translated message (strings.errors.bridge.<code>), others keep the English
     * message of the bridge.
     */
    protected function toException(Response $response): BridgeException
    {
        $data = $response->json();

        return $this->exceptionFromError(is_array($data) ? $data : [], $response->status());
    }

    /**
     * An error object of the bridge as exception: the body of an error answer, or error of a failed
     * profile. Codes the plugin knows get a translated message (strings.errors.bridge.<code>), others keep
     * the English message of the bridge. The code decides, never the HTTP status: 404 is not_found or
     * server_not_found, 401 is unauthorized, 403 ip_not_allowed.
     *
     * @param  array<string, mixed>  $error  {"error": "<code>", "message": "…", "details": {…}|null}
     */
    public function exceptionFromError(array $error, ?int $status = null): BridgeException
    {
        $code = is_string($error['error'] ?? null) && $error['error'] !== '' ? $error['error'] : null;
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        // Without an error body, e.g. from a proxy in between that still passed the User-Agent on.
        $code ??= match ($status) {
            401 => BridgeException::UNAUTHORIZED,
            default => BridgeException::BRIDGE_ERROR,
        };

        $key = MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.bridge.'.$code;

        if (Lang::has($key)) {
            $message = trans($key, $this->replacements($details));

            if ($code === 'attach_unavailable' && is_string($details['reason'] ?? null)) {
                $reasonKey = MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.attach_reasons.'.$details['reason'];
                $message .= ' '.(Lang::has($reasonKey) ? trans($reasonKey) : trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.errors.attach_reasons.unknown'));
            }
        } elseif ($message === '') {
            $message = $this->trans('errors.bridge_error', ['status' => $status ?? 0]);
        }

        return new BridgeException($code, $message, $status, details: $details);
    }

    /**
     * Scalar details of an error as replacements for its message, sizes readable.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, scalar>
     */
    protected function replacements(array $details): array
    {
        $replace = [];

        foreach ($details as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $replace[$key] = str_ends_with((string) $key, '_bytes') && is_numeric($value) ? Format::bytes((int) $value) : $value;
        }

        return $replace;
    }

    /**
     * @param  array<string, scalar>  $replace
     */
    protected function trans(string $key, array $replace = []): string
    {
        return trans(MinecraftPerformanceAnalyserPlugin::ID.'::strings.'.$key, $replace);
    }
}
