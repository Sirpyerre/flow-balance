<?php

declare(strict_types=1);

use FlowBalance\Shared\Http\ClientIpResolver;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ClientIpResolverTest extends TestCase
{
    public function testUsesOnlyAuthenticatedMetadataFromAConfiguredPeer(): void
    {
        $resolver = new ClientIpResolver(['127.0.0.1'], 'test-proxy-token');
        $proxied = (new ServerRequest(['REMOTE_ADDR' => '::ffff:127.0.0.1']))
            ->withHeader('X-Flow-Proxy-Token', 'test-proxy-token')
            ->withHeader('X-Flow-Client-IP', '2001:0db8::12');
        self::assertSame('2001:db8::12', $resolver->resolve($proxied));
        self::assertSame('127.0.0.1', $resolver->resolve($proxied->withoutHeader('X-Flow-Proxy-Token')));
        self::assertSame('127.0.0.1', $resolver->resolve($proxied->withHeader('X-Flow-Client-IP', ['192.0.2.12', '192.0.2.13'])));
        self::assertSame('192.0.2.20', $resolver->resolve(new ServerRequest(['REMOTE_ADDR' => '192.0.2.20'], headers: $proxied->getHeaders())));
        self::assertSame('127.0.0.1', (new ClientIpResolver(['127.0.0.1'], ''))->resolve($proxied));
    }
}
