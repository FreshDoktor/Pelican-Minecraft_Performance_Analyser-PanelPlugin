<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Tests\Unit;

use FreshDoktor\MinecraftPerformanceAnalyser\Exceptions\BridgeException;
use FreshDoktor\MinecraftPerformanceAnalyser\Services\BridgeClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether an answer comes from the bridge of the node: the User-Agent with its token id, except the 403
 * ip_not_allowed a bridge sends without one to an address outside its allowed_ips.
 */
class BridgeIdentityTest extends TestCase
{
    protected const TOKEN_ID = 'Xy12Ab34Cd56Ef78';

    protected const REFUSED = ['error' => 'ip_not_allowed', 'message' => 'This address is not in allowed_ips.', 'details' => null];

    /**
     * @return array<string, array{0: string, 1: int, 2: mixed, 3: string|null}>
     */
    public static function answers(): array
    {
        $ua = 'MPA Bridge/v1.1.0 (id:'.self::TOKEN_ID.')';

        return [
            'the bridge of the node' => [$ua, 200, ['state' => 'ready'], null],
            'the bridge of the node, an error' => [$ua, 401, ['error' => 'unauthorized'], null],
            // An older bridge sends its User-Agent on the 403, too: an error of the right node.
            'the bridge of the node, ip refused' => [$ua, 403, self::REFUSED, null],
            'the bridge of another node' => ['MPA Bridge/v1.1.0 (id:Other12345678901)', 200, ['state' => 'ready'], BridgeException::WRONG_NODE],
            'another node refusing the ip' => ['MPA Bridge/v1.1.0 (id:Other12345678901)', 403, self::REFUSED, BridgeException::WRONG_NODE],
            'a bridge refusing the ip' => ['', 403, self::REFUSED, BridgeException::IP_NOT_ALLOWED],
            'a proxy in between refusing the ip' => ['nginx', 403, self::REFUSED, BridgeException::IP_NOT_ALLOWED],
            'another 403' => ['', 403, ['error' => 'forbidden'], BridgeException::WRONG_NODE],
            'a 403 page' => ['', 403, null, BridgeException::WRONG_NODE],
            'ip_not_allowed with another status' => ['', 401, self::REFUSED, BridgeException::WRONG_NODE],
            'another service' => ['', 200, ['state' => 'ready'], BridgeException::WRONG_NODE],
        ];
    }

    #[DataProvider('answers')]
    public function test_identity(string $userAgent, int $status, mixed $body, ?string $code): void
    {
        $this->assertSame($code, BridgeClient::identityError($userAgent, self::TOKEN_ID, $status, $body));
    }

    public function test_ip_not_allowed_has_a_text_in_both_languages(): void
    {
        foreach (['en', 'de'] as $locale) {
            $strings = require dirname(__DIR__, 2)."/lang/$locale/strings.php";

            // verifyIdentity() shows strings.errors.<code> with the address.
            foreach ([BridgeException::IP_NOT_ALLOWED, BridgeException::WRONG_NODE] as $code) {
                $this->assertStringContainsString(':url', $strings['errors'][$code], "$locale: errors.$code");
            }

            $this->assertStringContainsString('allowed_ips', $strings['errors'][BridgeException::IP_NOT_ALLOWED]);
        }
    }
}
