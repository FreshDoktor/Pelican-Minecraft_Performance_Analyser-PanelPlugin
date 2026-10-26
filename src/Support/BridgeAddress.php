<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

/**
 * Which bridge addresses the panel may send the bridge token to, and the form of a certificate pin.
 *
 * The bridge serves https by default (its own certificate, the one of Wings, or a self-signed one the panel
 * pins). Plain http sends the token in cleartext, so it is only allowed where it never crosses the internet:
 * localhost and loopback, private and CGNAT networks (Tailscale), unique local and link-local addresses.
 * Only IP literals and the name localhost count; a host name with http is refused, nothing is resolved, a
 * name may point anywhere.
 */
final class BridgeAddress
{
    /**
     * Pin of the bridge's certificate as mpa-bridge -fingerprint prints it, the form of cURL's
     * CURLOPT_PINNEDPUBLICKEY: sha256// and the base64 SHA-256 of the public key (SubjectPublicKeyInfo). One
     * pin only (cURL would take any of several separated by ;), $ without a trailing newline (D).
     */
    public const PIN_PATTERN = '/^sha256\/\/[A-Za-z0-9+\/]{43}=$/D';

    /** Networks plain http is allowed to, as CIDR. */
    public const HTTP_NETWORKS = [
        '127.0.0.0/8',    // loopback
        '10.0.0.0/8',     // private
        '172.16.0.0/12',  // private
        '192.168.0.0/16', // private
        '100.64.0.0/10',  // CGNAT, e.g. Tailscale
        '169.254.0.0/16', // link-local
        '::1/128',        // loopback
        'fc00::/7',       // unique local
        'fe80::/10',      // link-local
    ];

    /**
     * Whether the panel may send the token to this address: any https address, an http address only to a
     * host plain http is allowed to (see allowsHttp()). Other schemes and addresses without a host are refused.
     */
    public static function isAllowed(string $url): bool
    {
        $parts = parse_url(trim($url));

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return false;
        }

        return match (strtolower($parts['scheme'])) {
            'https' => true,
            // A user in the address would make it harder to tell which host is asked. IPv6 belongs in brackets.
            'http' => !isset($parts['user']) && !isset($parts['pass'])
                && (!str_contains($parts['host'], ':') || str_starts_with($parts['host'], '['))
                && self::allowsHttp($parts['host']),
            default => false,
        };
    }

    /**
     * Whether plain http is allowed to this host: localhost, or an IP literal in one of HTTP_NETWORKS. IPv6
     * may be in brackets as in an address ([fd00::5]). An IPv4 address mapped into IPv6 counts as the IPv4
     * address. Host names are never resolved.
     */
    public static function allowsHttp(string $host): bool
    {
        $host = strtolower(trim($host));

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($host === 'localhost') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ip = inet_pton($host);

        if ($ip === false) {
            return false;
        }

        // ::ffff:10.0.0.5 is 10.0.0.5.
        if (strlen($ip) === 16 && str_starts_with($ip, str_repeat("\0", 10)."\xff\xff")) {
            $ip = substr($ip, 12);
        }

        foreach (self::HTTP_NETWORKS as $network) {
            if (self::inNetwork($ip, $network)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a pin has the form mpa-bridge -fingerprint prints, see PIN_PATTERN.
     */
    public static function isValidPin(?string $pin): bool
    {
        return $pin !== null && preg_match(self::PIN_PATTERN, $pin) === 1;
    }

    /**
     * @param  string  $ip  packed, as from inet_pton()
     */
    protected static function inNetwork(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $network = (string) inet_pton($network);
        $bits = (int) $bits;

        if (strlen($ip) !== strlen($network)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if (substr($ip, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }

        if ($bits % 8 === 0) {
            return true;
        }

        $mask = (0xff << (8 - $bits % 8)) & 0xff;

        return (ord($ip[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }
}
