<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use FlowBalance\Finance\Application\OwnedResourceChanges;
use FlowBalance\Finance\Infrastructure\PostgresOwnedResourceRepository;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class OwnedResourceHandler implements RequestHandlerInterface
{
    public function __construct(private string $type) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $userId = $request->getAttribute(SessionInterface::class)->get('userId');
        $id = $request->getAttribute('id');
        $repository = new PostgresOwnedResourceRepository($request->getAttribute(PDO::class));
        $writing = $request->getMethod() === 'PATCH';
        $resource = OwnedResourceChanges::identifier($id)
            ? $repository->find($this->type, $userId, $id, $writing)
            : null;
        if ($resource === null) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Recurso no disponible']], 404);
        }
        if (!$writing) {
            return new JsonResponse($resource);
        }
        try {
            $changes = (new OwnedResourceChanges())->validate($this->type, (array) $request->getParsedBody());
            if (isset($changes['clientId']) && !$repository->find('clients', $userId, $changes['clientId'], true)) {
                throw new InvalidArgumentException('El cliente seleccionado no está disponible');
            }
        } catch (InvalidArgumentException $error) {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => $error->getMessage()]], 422);
        }

        return new JsonResponse($repository->update($this->type, $userId, $id, $changes));
    }
}
