<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use FlowBalance\Finance\Application\OwnedResourceChanges;
use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\PaymentExcess;
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
        $database = $request->getAttribute(PDO::class);
        $repository = new PostgresOwnedResourceRepository($database);
        $writing = $request->getMethod() === 'PATCH';
        $resource = OwnedResourceChanges::identifier($id)
            ? ($writing ? $repository->find($this->type, $userId, $id, $this->type !== 'payments')
                : $repository->detail($this->type, $userId, $id))
            : null;
        if ($resource === null) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Recurso no disponible']], 404);
        }
        if (!$writing) {
            return new JsonResponse($resource);
        }
        try {
            $changes = (new OwnedResourceChanges())->validate($this->type, (array) $request->getParsedBody());
            if ($this->type === 'payments') {
                $excess = new PaymentExcess($database);
                $project = $excess->project($userId, $resource['projectId'], true);
                $resource = $repository->find('payments', $userId, $id, true);
                $amount = $changes['clientPaidAmount'];
                new PaymentAmounts($amount, $resource['platformFee'], $resource['withheldTaxAmount']);
                $increased = (int) str_replace('.', '', $amount) > (int) str_replace('.', '', $resource['clientPaidAmount']);
                if ($increased && $excess->calculate($userId, $resource['projectId'], $project['agreed_amount'], $amount, $id)['requiresExcessReason']) {
                    throw new InvalidArgumentException('El cambio aumentaría el excedente del proyecto.');
                }
            }
            if (isset($changes['clientId']) && $changes['clientId'] !== $resource['clientId'] && $repository->hasPayments($userId, $id)) {
                return new JsonResponse(['error' => ['code' => 'project_has_payments', 'message' => 'No se puede cambiar el cliente de un proyecto con cobros.']], 409);
            }
            if (isset($changes['clientId']) && !$repository->find('clients', $userId, $changes['clientId'], true)) {
                throw new InvalidArgumentException('El cliente seleccionado no está disponible');
            }
        } catch (InvalidArgumentException $error) {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => $error->getMessage()]], 422);
        }

        return new JsonResponse($repository->update($this->type, $userId, $id, $changes));
    }
}
