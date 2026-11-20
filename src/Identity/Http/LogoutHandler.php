<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class LogoutHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        $database = $request->getAttribute(PDO::class);
        $query = $database->prepare(
            "UPDATE server_sessions SET revoked_at = CURRENT_TIMESTAMP(6), payload = '{}'
             WHERE token_hash = ? AND revoked_at IS NULL"
        );
        $query->execute([hash('sha256', $session->getId())]);

        return new EmptyResponse(204, [
            'Set-Cookie' => '__Host-flow_balance=; Max-Age=0; Path=/; Secure; HttpOnly; SameSite=Lax',
        ]);
    }
}
