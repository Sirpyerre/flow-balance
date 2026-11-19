<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;

final readonly class ClientIpResolver
{
    public function __construct(private array $trustedPeers, private string $token) {}

    public function resolve(ServerRequestInterface $request): string
    {
        $peer = self::normalize($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($this->token === '' || !in_array($peer, array_map(self::normalize(...), $this->trustedPeers), true) || $peer === '') {
            return $peer;
        }
        if (!hash_equals($this->token, $request->getHeaderLine('X-Flow-Proxy-Token'))) {
            return $peer;
        }
        return self::normalize($request->getHeaderLine('X-Flow-Client-IP')) ?: $peer;
    }

    private static function normalize(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $bytes = inet_pton($address);
        if (strlen($bytes) === 16 && substr($bytes, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $bytes = substr($bytes, 12);
        }
        return inet_ntop($bytes);
    }
}
