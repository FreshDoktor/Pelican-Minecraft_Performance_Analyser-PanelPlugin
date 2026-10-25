<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * What the bridge can do for a server right now, from its GET /status (docs/API.md): the features of the
 * bridge, the state of the container and the JVM, and whether the bridge may attach to it.
 *
 * The page only shows what can deliver data. Each action is either available, or has a reason why not:
 *
 * - Reasons in HIDING_REASONS can't be changed by the user of the server: no bridge, a bridge without the
 *   feature, a server without a Server thread (a proxy). The tab or button is left out.
 * - All other reasons are passing or can be fixed by the user: the server is stopped, the JVM is starting,
 *   the startup command has -XX:+DisableAttachMechanism or -Xrs. The button stays, disabled, with the reason.
 */
final class Capabilities
{
    /** A lag analysis (profile). */
    public const PROFILE = 'profile';

    /** A class histogram. */
    public const HISTOGRAM = 'histogram';

    /** A thread dump. */
    public const THREADS = 'threads';

    /** The live heap in the overview (GET /metrics?live=1). */
    public const LIVE_HEAP = 'live_heap';

    /** Feature of the bridge each action needs (docs/API.md, "Versioning"). Each of them attaches to the JVM. */
    public const FEATURES = [
        self::PROFILE => 'profiles',
        self::HISTOGRAM => 'histogram',
        self::THREADS => 'threads',
        self::LIVE_HEAP => 'live_heap',
    ];

    public const BRIDGE_MISSING = 'bridge_missing';

    /** The bridge answers, but with an error (token, allowed_ips, Docker); the overview says which. */
    public const BRIDGE_ERROR = 'bridge_error';

    public const NOT_IMPLEMENTED = 'not_implemented';

    public const NO_SERVER_THREAD = 'no_server_thread';

    public const HIDING_REASONS = [self::BRIDGE_MISSING, self::BRIDGE_ERROR, self::NOT_IMPLEMENTED, self::NO_SERVER_THREAD];

    /** Every reason, for the texts (strings.capabilities.<reason>). */
    public const REASONS = [
        ...self::HIDING_REASONS,
        'server_not_running', 'server_paused', 'no_java_process', 'hsperf_unavailable',
        'attach_disabled', 'attach_xrs', 'attach_jvm_starting', 'attach_unknown',
    ];

    /** Warning of a profile result without a thread called Server thread. */
    public const SERVER_THREAD_MISSING = 'server_thread_missing';

    /**
     * @param  array<string, mixed>|null  $status  answer of GET /status, null when the bridge failed
     */
    private function __construct(
        private readonly ?array $status,
        private readonly ?string $bridgeFailure,
        private readonly bool $serverThreadMissing,
    ) {}

    /**
     * @param  array<string, mixed>  $status  answer of GET /status
     * @param  bool  $proxy  the egg is a proxy (tag proxy, e.g. Velocity, BungeeCord): no Server thread, no ticks
     * @param  array<string, mixed>|null  $lastProfile  the newest stored profile object of the server; if it was
     *                                                  taken of this JVM and found no Server thread, there is none
     */
    public static function fromStatus(array $status, bool $proxy = false, ?array $lastProfile = null): self
    {
        return new self($status, null, $proxy || self::profileFoundNoServerThread($status, $lastProfile));
    }

    /**
     * The bridge could not be asked.
     *
     * @param  bool  $missing  no bridge answers at all (BridgeException::isBridgeMissing), else it answered with an error
     */
    public static function bridgeFailed(bool $missing): self
    {
        return new self(null, $missing ? self::BRIDGE_MISSING : self::BRIDGE_ERROR, false);
    }

    /**
     * Why an action can't be done right now, null if it can.
     */
    public function reason(string $action): ?string
    {
        if ($this->bridgeFailure !== null) {
            return $this->bridgeFailure;
        }

        $features = $this->status['bridge']['features'] ?? [];

        if (!is_array($features) || !in_array(self::FEATURES[$action] ?? $action, $features, true)) {
            return self::NOT_IMPLEMENTED;
        }

        if ($action === self::PROFILE && $this->serverThreadMissing) {
            return self::NO_SERVER_THREAD;
        }

        $state = $this->status['state'] ?? null;

        if ($state === 'server_not_running' || ($this->status['server']['running'] ?? true) === false) {
            return ($this->status['server']['container_state'] ?? null) === 'paused' ? 'server_paused' : 'server_not_running';
        }

        if ($state === 'no_java_process' || !is_array($this->status['java'] ?? null)) {
            return 'no_java_process';
        }

        if ($state === 'hsperf_unavailable') {
            return 'hsperf_unavailable';
        }

        $attach = $this->status['attach'] ?? null;

        if (!is_array($attach) || ($attach['available'] ?? false) !== true) {
            $reason = is_array($attach) ? ($attach['reason'] ?? null) : null;

            // Unknown values of the enum count as unknown, as the contract asks.
            return 'attach_'.(in_array($reason, ['disabled', 'xrs', 'jvm_starting'], true) ? $reason : 'unknown');
        }

        return null;
    }

    public function available(string $action): bool
    {
        return $this->reason($action) === null;
    }

    /**
     * Whether the action is left out instead of shown disabled.
     */
    public function hidden(string $action): bool
    {
        return in_array($this->reason($action), self::HIDING_REASONS, true);
    }

    /**
     * Whether the action is shown disabled, for a reason that may pass or the user can fix. Worth asking
     * again after a while.
     */
    public function disabled(string $action): bool
    {
        $reason = $this->reason($action);

        return $reason !== null && !in_array($reason, self::HIDING_REASONS, true);
    }

    public function serverThreadMissing(): bool
    {
        return $this->serverThreadMissing;
    }

    /**
     * Whether a profile of the JVM that runs now had no thread called Server thread. A profile of an earlier
     * JVM says nothing, the server may have been switched to another egg since.
     *
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>|null  $profile
     */
    private static function profileFoundNoServerThread(array $status, ?array $profile): bool
    {
        $warnings = $profile['result']['warnings'] ?? null;
        $jvmStarted = $status['java']['started_at'] ?? null;

        return is_array($warnings)
            && in_array(self::SERVER_THREAD_MISSING, $warnings, true)
            && is_int($jvmStarted)
            && (int) ($profile['started_at'] ?? 0) >= $jvmStarted;
    }
}
