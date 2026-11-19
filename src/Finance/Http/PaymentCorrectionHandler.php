<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use DomainException;
use FlowBalance\Finance\Application\{DeletePayment, EditPayment, IncomeNotFound, IncomeValidation, OwnedResourceChanges, PaymentVersionConflict};
use FlowBalance\Finance\Infrastructure\{PostgresOwnedResourceRepository, PostgresPaymentCorrectionRepository};
use Laminas\Diactoros\Response\{EmptyResponse, JsonResponse};
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class PaymentCorrectionHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $database = $request->getAttribute(PDO::class);
        $repository = new PostgresPaymentCorrectionRepository($database);
        $userId = $request->getAttribute(SessionInterface::class)->get('userId');
        $id = $request->getAttribute('id');
        $body = (array) $request->getParsedBody();
        try {
            if (!OwnedResourceChanges::identifier($id)) {
                throw new IncomeNotFound();
            }
            if ($request->getMethod() === 'DELETE') {
                return (new DeletePayment($repository))->delete($userId, $id, $request->getHeaderLine('If-Match'), $body)
                    ? new EmptyResponse()
                    : self::error(428, 'version_required', 'Vuelve a abrir el cobro antes de eliminarlo.');
            }
            $version = $request->getHeaderLine('If-Match');
            if (preg_match('/\A"([0-9]+)"\z/', $version, $match)) {
                $version = $match[1];
            } elseif ($version !== '') {
                $version = 'invalid';
            }
            $edit = new EditPayment($repository);
            if ($request->getMethod() === 'POST') {
                return new JsonResponse($edit->preview($userId, $id, $body, $version));
            }
            $edit->edit($userId, $id, $body, $version, $request->getHeaderLine('Idempotency-Key'));
            return new JsonResponse((new PostgresOwnedResourceRepository($database))->detail('payments', $userId, $id));
        } catch (IncomeNotFound) {
            return self::error(404, 'not_found', 'Recurso no disponible');
        } catch (PaymentVersionConflict) {
            return self::error(412, 'version_conflict', 'El cobro cambió. Revisa los datos actuales antes de continuar.');
        } catch (IncomeValidation $error) {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => $error->getMessage(),
                'fields' => [$error->field => $error->getMessage()]]], 422);
        } catch (DomainException $error) {
            return self::error(409, 'idempotency_conflict', $error->getMessage());
        }
    }

    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
