<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Http;

use FlowBalance\Identity\Application\LoginUser;
use FlowBalance\Identity\Domain\RegistrationInput;
use FlowBalance\Identity\Domain\SessionPolicy;
use FlowBalance\Shared\Clock\Clock;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LoginHandler implements RequestHandlerInterface
{
    public function __construct(private LoginUser $login, private Clock $clock) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = is_string($data['email'] ?? null) ? trim($data['email'], " \t\n\r\v\f") : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        $fields = [];
        if (!RegistrationInput::validEmail($email)) $fields['email'] = 'Ingresa un correo válido';
        if ($password === '') $fields['password'] = 'Ingresa tu contraseña';
        if ($fields) {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Revisa los campos indicados', 'fields' => $fields]], 422);
        }
        $now = $this->clock->now();
        $result = $this->login->login($email, $password, $request->getServerParams()['REMOTE_ADDR'] ?? '', $now);
        if (isset($result['retryAfter'])) {
            return new JsonResponse(['error' => ['code' => 'login_temporarily_blocked',
                'message' => 'Demasiados intentos. Intenta de nuevo en 15 minutos']], 429,
                ['Retry-After' => (string) $result['retryAfter']]);
        }
        if (!isset($result['user'])) {
            return new JsonResponse(['error' => ['code' => 'invalid_credentials',
                'message' => 'El correo o la contraseña son incorrectos']], 401);
        }
        $session = $request->getAttribute(SessionInterface::class)->regenerate();
        $session->clear();
        $session->set('userId', $result['user']['id']);
        $session->set('authenticatedAt', $now);
        $session->set('lastActivityAt', $now);
        return new JsonResponse(['user' => $result['user'], 'session' => [
            'idleExpiresAt' => gmdate(DATE_ATOM, $now + SessionPolicy::IDLE_SECONDS),
            'absoluteExpiresAt' => gmdate(DATE_ATOM, $now + SessionPolicy::ABSOLUTE_SECONDS),
        ]]);
    }
}
