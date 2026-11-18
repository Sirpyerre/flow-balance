<?php

declare(strict_types=1);

namespace FlowBalance\Identity\Http;

use FlowBalance\Identity\Application\RegisterUser;
use FlowBalance\Identity\Domain\RegistrationInput;
use FlowBalance\Identity\Domain\SessionPolicy;
use FlowBalance\Shared\Clock\Clock;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class RegistrationHandler implements RequestHandlerInterface
{
    public function __construct(private RegisterUser $registration, private Clock $clock) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $input = new RegistrationInput((array) $request->getParsedBody());
        if ($input->errors) {
            return new JsonResponse(['error' => [
                'code' => 'validation_failed', 'message' => 'Revisa los campos indicados', 'fields' => $input->errors,
            ]], 422);
        }
        $user = $this->registration->register($input);
        if ($user === null) {
            return new JsonResponse(['error' => [
                'code' => 'duplicate_email', 'message' => 'No se pudo crear la cuenta con este correo',
            ]], 422);
        }

        $session = $request->getAttribute(SessionInterface::class)->regenerate();
        $session->clear();
        $now = $this->clock->now();
        $session->set('userId', $user['id']);
        $session->set('authenticatedAt', $now);
        $session->set('lastActivityAt', $now);
        return new JsonResponse(['user' => $user, 'session' => [
            'idleExpiresAt' => gmdate(DATE_ATOM, $now + SessionPolicy::IDLE_SECONDS),
            'absoluteExpiresAt' => gmdate(DATE_ATOM, $now + SessionPolicy::ABSOLUTE_SECONDS),
        ]], 201);
    }
}
