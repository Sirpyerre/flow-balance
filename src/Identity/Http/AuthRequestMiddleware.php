<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Http;

use JsonException;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Csrf\SessionCsrfGuard;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AuthRequestMiddleware implements MiddlewareInterface
{
    public function __construct(private string $origin, private bool $requireAnonymous = true) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionInterface::class);
        if ($this->requireAnonymous && $session->has('userId')) {
            return self::error(409, 'already_authenticated', 'Ya iniciaste sesión');
        }
        $token = $request->getHeaderLine('X-CSRF-Token');
        if ($request->getHeaderLine('Origin') !== $this->origin || $token === ''
            || !(new SessionCsrfGuard($session))->validateToken($token)) {
            return self::error(403, 'invalid_csrf', 'No se pudo verificar la solicitud');
        }
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0])) !== 'application/json') {
            return self::error(415, 'unsupported_media_type', 'Envía los datos en formato JSON');
        }
        $body = $request->getBody()->read(16385);
        if (strlen($body) > 16384) {
            return self::error(413, 'payload_too_large', 'La solicitud es demasiado grande');
        }
        try {
            $data = json_decode($body, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::error(400, 'invalid_json', 'La solicitud no contiene JSON válido');
        }
        if (!$data instanceof \stdClass) {
            return self::error(400, 'invalid_json', 'La solicitud debe contener un objeto JSON');
        }
        return $handler->handle($request->withParsedBody((array) $data));
    }

    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
