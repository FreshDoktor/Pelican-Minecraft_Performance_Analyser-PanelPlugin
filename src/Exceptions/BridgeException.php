<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Exceptions;

use Exception;
use Throwable;

/**
 * The bridge could not be asked or answered with an error. The message is translated and can be shown
 * to the user, the code tells what happened.
 *
 * Codes the plugin sets itself are the constants below. Errors of the bridge keep the code of its
 * answer ({"error": "<code>", "message": "…", "details": …}, e.g. server_not_running), see "Errors" in
 * docs/API.md of the bridge (wing commit 67a8f92).
 */
class BridgeException extends Exception
{
    /** No address or no token is set for the node's bridge, so the panel didn't contact the node at all. */
    public const NOT_CONFIGURED = 'not_configured';

    /** No connection: no bridge on the node, a wrong address or a firewall in between. */
    public const UNREACHABLE = 'unreachable';

    /** Connected, but the bridge didn't answer within the panel's timeout. */
    public const TIMEOUT = 'timeout';

    /** The bridge refused the bridge token set for the node (same code as the bridge's own). */
    public const UNAUTHORIZED = 'unauthorized';

    /** Something answered that is not the bridge of this node (User-Agent missing or for another token). */
    public const WRONG_NODE = 'wrong_node';

    /** The answer was no JSON or had another form than expected. */
    public const INVALID_RESPONSE = 'invalid_response';

    /** The bridge failed without saying why. */
    public const BRIDGE_ERROR = 'bridge_error';

    /**
     * @param  array<string, mixed>  $details  details of the bridge's error, e.g. container_state
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
        public readonly array $details = [],
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether there is no bridge to talk to, so the page shows how to install one instead of an error.
     */
    public function isBridgeMissing(): bool
    {
        return in_array($this->errorCode, [self::NOT_CONFIGURED, self::UNREACHABLE, self::WRONG_NODE], true);
    }
}
