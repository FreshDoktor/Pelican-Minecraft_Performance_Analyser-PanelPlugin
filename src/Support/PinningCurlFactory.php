<?php

namespace FreshDoktor\MinecraftPerformanceAnalyser\Support;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlFactoryInterface;
use GuzzleHttp\Handler\EasyHandle;
use Psr\Http\Message\RequestInterface;
use UnexpectedValueException;

/**
 * Builds Guzzle's cURL handles and pins the bridge's certificate on each: CURLOPT_PINNEDPUBLICKEY with the
 * pin of the node (see BridgeAddress::PIN_PATTERN). cURL then refuses any certificate with another key during
 * the handshake, before the token is sent, also with verify off.
 *
 * Guzzle 8 rejects the option in the "curl" request option (outside its allow-list), so it is set on the
 * handle Guzzle built, right before the transfer. Use it as handle_factory of a CurlHandler.
 */
final class PinningCurlFactory implements CurlFactoryInterface
{
    private CurlFactoryInterface $factory;

    public function __construct(private string $pin, ?CurlFactoryInterface $factory = null)
    {
        $this->factory = $factory ?? new CurlFactory(3);
    }

    /**
     * @throws UnexpectedValueException when this cURL can't pin (built without support for it)
     */
    public function create(RequestInterface $request, array $options): EasyHandle
    {
        $easy = $this->factory->create($request, $options);

        if (!defined('CURLOPT_PINNEDPUBLICKEY') || !curl_setopt($easy->handle, CURLOPT_PINNEDPUBLICKEY, $this->pin)) {
            // Never send without the pin: verify is off, any certificate would pass.
            $this->factory->release($easy);

            throw new UnexpectedValueException('cURL cannot pin the certificate of the bridge.');
        }

        return $easy;
    }

    public function release(EasyHandle $easy): void
    {
        $this->factory->release($easy);
    }
}
