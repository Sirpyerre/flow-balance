<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Http;

use Closure;
use FlowBalance\Shared\Configuration\InvalidConfiguration;
use Laminas\Diactoros\Response\JsonResponse;
use PDO;
use PDOException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers whether the site can serve requests: its configuration is valid and the database answers. It opens no
 * session and no transaction and writes nothing, so a deployment can ask it as often as it likes.
 */
final readonly class HealthCheck implements RequestHandlerInterface
{
    /** @param Closure(): mixed $settings validates the configuration; @param Closure(): PDO $connect */
    public function __construct(private Closure $settings, private Closure $connect) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            ($this->settings)();
            ($this->connect)()->query('SELECT 1')->fetchColumn();
        } catch (InvalidConfiguration $error) {
            error_log('Flow Balance configuration failure: ' . $error->getMessage());
            return $this->unavailable('configuration_unavailable');
        } catch (PDOException $error) {
            error_log('Flow Balance database failure: ' . $error->getCode());
            return $this->unavailable('persistence_unavailable');
        }
        return new JsonResponse(['status' => 'ok']);
    }

    private function unavailable(string $code): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => 'Intenta de nuevo más tarde']], 503);
    }
}
