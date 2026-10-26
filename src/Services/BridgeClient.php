<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Services;

use App\Models\Node;
use App\Models\Server;
use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\MinecraftPerformanceAnalyserPlugin;
use FreshDoktor\MinecraftPerformanceAnalyser\Models\NodeBridge;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\BridgeAddress;
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
 * Built like Pelican's Http::daemon() macro (AppServiceProvider) and DaemonRepository: a token as
 * Bearer, and every answer has to carry the User-Agent "MPA Bridge/v<version> (id:<token id>)" with the
 * token id of this node, so the panel knows it talks to the bridge of the right node. Unlike Wings the
 * bridge has its own address and its own token per node (see NodeBridge), never the token of Wings. A
 * node without both has no bridge: no request is sent then (not_configured), the token only goes to the
 * address the admin set for it, and redirects are not followed. Addresses are https (the bridge serves it
 * by default), the certificate checked by the pin set for the node, else against the CA store; plain
 * http only to loopback and private addresses (BridgeAddress), else nothing is sent (insecure_url).
 *
 * Endpoints, fields and error codes follow docs/API.md of the bridge, API v1 as of wing commit 54797fe (bridge 1.1.0, its own
 * token instead of the one of Wings, https by default). Unknown fields are ignored, as the contract asks.
 */
class BridgeClient
{
    public const API_PREFIX = 'api/v1';

    public const API_VERSION = 1;

    /** Commit of docs/API.md in the bridge repo this client was written against. */
    public const CONTRACT = '54797fe';

    /** Address the fake mode plays, it needs no bridge set for the node. */
    public const FAKE_URL = 'http://mpa-bridge.fake';

    /** Like DaemonRepository::enforceValidNodeToken() with "Pelican Wings", group 1 is the token id. */
    public const USER_AGENT_PATTERN = '/^MPA Bridge\/v(?:\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-+]+)?|develop) \(id:(\w*)\)$/';

    /** Values of state in GET /status besides ready, each is also the error code /vm and /metrics answer with. */
    public const NOT_READY_STATES = ['server_not_running', 'no_java_process', 'hsperf_unavailable'];

    /** @var array<int, NodeBridge|null> address and token by node id, looked up once per request */
    protected array $bridges = [];

    /**
     * Address and token of the bridge of a node, set in the admin area, null if there is none.
     */
    public function bridgeFor(Node $node): ?NodeBridge
    {
        if (!array_key_exists($node->id, $this->bridges)) {
            $this->bridges[$node->id] = NodeBridge::for($node);
        }

        return $this->bridges[$node->id];
    }

    /**
     * Address of the bridge of a node as set in the admin area, null if there is none.
     */
    public function baseUrl(Node $node): ?string
    {
        return NodeBridge::normalize($this->bridgeFor($node)?->url);
    }

    /**
     * Whether the node has a bridge the panel may ask: address and token are both set (or the fake mode
     * plays one). Without one nothing is sent to the node.
     */
    public function isConfigured(Node $node): bool
    {
        return $this->isFake() || ($this->bridgeFor($node)?->isConfigured() ?? false);
    }

    /**
     * GET /api/v1/status: version, API version, features and limits of the bridge of a node. For the
     * test button and to tell whether a node has a bridge at all.
     *
     * @param  string|null  $baseUrl  the address to ask, null for the saved one
     * @param  string|null  $token  the token to send, null for the saved one
     * @param  string|null  $pin  the certificate pin to check, null for the saved one
     * @return array<string, mixed>
     *
     * @throws BridgeException
     */
    public function nodeStatus(Node $node, ?string $baseUrl = null, ?string $token = null, ?string $pin = null): array
    {
        return $this->send($node, 'GET', self::API_PREFIX.'/status', baseUrl: $baseUrl, token: $token, pin: $pin);
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
     * @param  string|null  $token  another token than the saved one, e.g. one to test before saving it
     * @param  string|null  $pin  another certificate pin than the saved one, e.g. one to test before saving it
     * @return array<mixed>
     *
     * @throws BridgeException
     */
    public function send(Node $node, string $method, string $path, array $options = [], ?int $timeout = null, ?string $baseUrl = null, ?string $token = null, ?string $pin = null): array
    {
        $response = $this->request($node, $method, $path, $options, $timeout, $baseUrl, $token, $pin);

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
     * Send a request and make sure the bridge of this node answered it, whatever the status. Without an
     * address and a token nothing is sent: the token must never go to an address nobody set for it, nor in
     * cleartext over plain http to a host that may be anywhere.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws BridgeException
     */
    protected function request(Node $node, string $method, string $path, array $options, ?int $timeout, ?string $baseUrl, ?string $token, ?string $pin = null): Response
    {
        $baseUrl = NodeBridge::normalize($baseUrl) ?? $this->baseUrl($node);
        $token = NodeBridge::normalizeToken($token) ?? NodeBridge::normalizeToken($this->bridgeFor($node)?->token);
        $pin = NodeBridge::normalizePin($pin) ?? NodeBridge::normalizePin($this->bridgeFor($node)?->tls_pin);

        if ($this->isFake()) {
            // The fake mode answers every request itself, it needs no bridge set for the node.
            [$baseUrl, $token, $pin] = [$baseUrl ?? self::FAKE_URL, $token ?? '', null];
        } elseif ($baseUrl === null || $token === null) {
            throw new BridgeException(BridgeException::NOT_CONFIGURED, $this->trans('errors.not_configured'));
        } elseif (!BridgeAddress::isAllowed($baseUrl)) {
            throw new BridgeException(BridgeException::INSECURE_URL, $this->trans('errors.insecure_url', ['url' => $baseUrl]));
        } elseif ($pin !== null && !BridgeAddress::isValidPin($pin)) {
            throw new BridgeException(BridgeException::INVALID_PIN, $this->trans('errors.invalid_pin'));
        }

        try {
            $response = $this->http($node, $baseUrl, $token, $timeout, $pin)->send($method, $path, $options);
        } catch (ConnectionException $exception) {
            // cURL error 28: connected, but no answer in time. The bridge is there, it is just slow (a long heap walk).
            if (str_contains($exception->getMessage(), 'cURL error 28') && !str_contains($exception->getMessage(), 'Connection timed out')) {
                throw new BridgeException(BridgeException::TIMEOUT, $this->trans('errors.timeout', ['url' => $baseUrl]), previous: $exception);
            }

            // cURL error 90: the key of the certificate is not the pinned one. Another service, or someone in between.
            if (str_contains($exception->getMessage(), 'cURL error 90')) {
                throw new BridgeException(BridgeException::PIN_MISMATCH, $this->trans('errors.pin_mismatch', ['url' => $baseUrl]), previous: $exception);
            }

            // cURL error 60: no pin, and the certificate isn't trusted, e.g. the bridge's self-signed one, or another name.
            if (str_contains($exception->getMessage(), 'cURL error 60')) {
                throw new BridgeException(BridgeException::CERTIFICATE_UNTRUSTED, $this->trans('errors.certificate_untrusted', ['url' => $baseUrl]), previous: $exception);
            }

            throw new BridgeException(BridgeException::UNREACHABLE, $this->trans('errors.unreachable', ['url' => $baseUrl]), previous: $exception);
        }

        // The bridge serves https and Go's server answers a plain http request with this 400, without a User-Agent.
        if ($response->status() === 400 && str_contains($response->body(), 'HTTP request to an HTTPS server')) {
            throw new BridgeException(BridgeException::INSECURE_URL, $this->trans('errors.https_required', ['url' => $baseUrl]), 400);
        }

        // Before anything else: an error page of some other service must not pass for an error of the bridge.
        $this->verifyIdentity($response, $node, $baseUrl);

        return $response;
    }

    protected function http(Node $node, string $baseUrl, string $token, ?int $timeout, ?string $pin = null): PendingRequest
    {
        // A redirect would take the token along to another address.
        $request = Http::acceptJson()
            ->withToken($token)
            ->withoutRedirecting()
            ->timeout($timeout ?? (int) config(MinecraftPerformanceAnalyserPlugin::ID.'.timeout', 10))
            ->connectTimeout((int) config(MinecraftPerformanceAnalyserPlugin::ID.'.connect_timeout', 3))
            ->baseUrl($baseUrl);

        if (str_starts_with(strtolower($baseUrl), 'https://') && $pin !== null) {
            // Without cURL Guzzle would ignore the pin and, without verify, accept any certificate.
            if (!defined('CURLOPT_PINNEDPUBLICKEY')) {
                throw new BridgeException(BridgeException::INVALID_PIN, $this->trans('errors.pin_without_curl'));
            }

            // The pin replaces the check against the CA store, so the bridge's self-signed certificate passes:
            // cURL compares the key of the certificate with the pin also without verify, and refuses any other.
            $request->withOptions(['verify' => false, 'curl' => [CURLOPT_PINNEDPUBLICKEY => $pin]]);
        } elseif (str_starts_with(strtolower($baseUrl), 'https://')) {
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
