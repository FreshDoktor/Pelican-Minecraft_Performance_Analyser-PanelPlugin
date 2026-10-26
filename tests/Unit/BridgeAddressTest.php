<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\Support\BridgeAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which addresses the panel sends the bridge token to (plain http only to loopback and private IPs), and the
 * form of a certificate pin as mpa-bridge -fingerprint prints it.
 */
class BridgeAddressTest extends TestCase
{
    /** A pin mpa-bridge -fingerprint printed. */
    protected const PIN = 'sha256//+6IzzDvfXgsEHYmcF3t3lfm9IAnYwJmz/ureqK1JWOg=';

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowed(): array
    {
        return [
            'https host name' => ['https://node.example.com:8090'],
            'https public IP' => ['https://203.0.113.5:8090'],
            'https, upper case' => ['HTTPS://NODE.EXAMPLE.COM:8090'],
            'localhost' => ['http://localhost:8090'],
            'localhost, upper case' => ['http://LocalHost:8090'],
            'loopback' => ['http://127.0.0.1:8090'],
            'loopback, other' => ['http://127.255.0.9:8090'],
            '10/8' => ['http://10.0.0.5:8090'],
            '172.16/12, first' => ['http://172.16.0.1:8090'],
            '172.16/12, last' => ['http://172.31.255.254:8090'],
            '192.168/16' => ['http://192.168.1.20:8090'],
            '100.64/10, Tailscale' => ['http://100.101.102.103:8090'],
            '100.64/10, last' => ['http://100.127.255.255:8090'],
            'link-local' => ['http://169.254.10.1:8090'],
            'IPv6 loopback' => ['http://[::1]:8090'],
            'IPv6 unique local fd' => ['http://[fd00::5]:8090'],
            'IPv6 unique local fc' => ['http://[fc12:3456::1]'],
            'IPv6 link-local' => ['http://[fe80::1]:8090'],
            'IPv6 link-local, upper case' => ['http://[FEBF::1]:8090'],
            'IPv4 mapped' => ['http://[::ffff:10.0.0.5]:8090'],
            'without port' => ['http://10.0.0.5'],
            'with path' => ['http://10.0.0.5:8090/'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refused(): array
    {
        return [
            'host name' => ['http://node.example.com:8090'],
            'host name resolving to a private IP' => ['http://10.0.0.5.nip.io:8090'],
            'localhost subdomain' => ['http://localhost.example.com:8090'],
            'public IP' => ['http://203.0.113.5:8090'],
            '172.15, below 172.16/12' => ['http://172.15.255.255:8090'],
            '172.32, above 172.16/12' => ['http://172.32.0.1:8090'],
            '100.63, below 100.64/10' => ['http://100.63.255.255:8090'],
            '100.128, above 100.64/10' => ['http://100.128.0.1:8090'],
            '192.169' => ['http://192.169.0.1:8090'],
            'unspecified' => ['http://0.0.0.0:8090'],
            'short IPv4 form' => ['http://127.1:8090'],
            'decimal IPv4' => ['http://2130706433:8090'],
            'hex IPv4' => ['http://0x7f.0.0.1:8090'],
            'public IPv6' => ['http://[2001:db8::1]:8090'],
            'IPv6 unspecified' => ['http://[::]:8090'],
            'IPv6 above fe80::/10' => ['http://[fec0::1]:8090'],
            'IPv4 mapped public' => ['http://[::ffff:203.0.113.5]:8090'],
            'IPv6 without brackets' => ['http://::1:8090'],
            'user in the address' => ['http://user@10.0.0.5:8090'],
            'user hiding the host' => ['http://10.0.0.5@node.example.com:8090'],
            'other scheme' => ['ftp://10.0.0.5:8090'],
            'no scheme' => ['10.0.0.5:8090'],
            'no host' => ['http://'],
            'empty' => [''],
        ];
    }

    #[DataProvider('allowed')]
    public function test_allowed_addresses(string $url): void
    {
        $this->assertTrue(BridgeAddress::isAllowed($url), $url);
    }

    #[DataProvider('refused')]
    public function test_refused_addresses(string $url): void
    {
        $this->assertFalse(BridgeAddress::isAllowed($url), $url);
    }

    public function test_http_hosts_with_and_without_brackets(): void
    {
        $this->assertTrue(BridgeAddress::allowsHttp('[::1]'));
        $this->assertTrue(BridgeAddress::allowsHttp('::1'));
        $this->assertTrue(BridgeAddress::allowsHttp('fd00::5'));
        $this->assertTrue(BridgeAddress::allowsHttp(' 10.0.0.5 '));
        $this->assertFalse(BridgeAddress::allowsHttp('[10.0.0.5]x'));
        $this->assertFalse(BridgeAddress::allowsHttp('[2001:db8::1]'));
        $this->assertFalse(BridgeAddress::allowsHttp('fe80::1%eth0'));
        $this->assertFalse(BridgeAddress::allowsHttp(''));
    }

    public function test_pin_format(): void
    {
        $this->assertTrue(BridgeAddress::isValidPin(self::PIN));
        $this->assertTrue(BridgeAddress::isValidPin('sha256//'.str_repeat('A', 43).'='));

        foreach ([
            null,
            '',
            substr(self::PIN, 8),                          // without sha256//
            'sha256/'.substr(self::PIN, 8),                // one slash
            'SHA256//'.substr(self::PIN, 8),               // upper case
            'sha1//'.substr(self::PIN, 8),
            substr(self::PIN, 0, -1),                      // without =
            substr(self::PIN, 0, -2).'=',                  // too short
            'sha256//'.str_repeat('A', 44).'=',            // too long
            'sha256//'.str_repeat('-', 43).'=',            // base64url
            self::PIN.';sha256//'.substr(self::PIN, 8),    // two pins, cURL would take either
            ' '.self::PIN,
            self::PIN."\n",
        ] as $pin) {
            $this->assertFalse(BridgeAddress::isValidPin($pin), var_export($pin, true));
        }
    }

    public function test_the_new_errors_have_a_text_in_both_languages(): void
    {
        foreach (['en', 'de'] as $locale) {
            $strings = require dirname(__DIR__, 2)."/lang/$locale/strings.php";

            foreach ([BridgeException::INSECURE_URL, BridgeException::INVALID_PIN, BridgeException::PIN_MISMATCH, BridgeException::CERTIFICATE_UNTRUSTED, 'https_required', 'pin_without_curl'] as $key) {
                $this->assertArrayHasKey($key, $strings['errors'], "$locale: errors.$key");
            }

            foreach (['pin', 'pin_helper', 'pin_invalid', 'url_insecure'] as $key) {
                $this->assertArrayHasKey($key, $strings['node'], "$locale: node.$key");
            }
        }

        // A wrong address or pin is no missing bridge: the admin has to fix it, the page says so.
        $this->assertFalse((new BridgeException(BridgeException::INSECURE_URL, ''))->isBridgeMissing());
        $this->assertFalse((new BridgeException(BridgeException::PIN_MISMATCH, ''))->isBridgeMissing());
    }
}
