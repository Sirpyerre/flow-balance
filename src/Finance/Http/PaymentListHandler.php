<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use DateTimeImmutable;
use FlowBalance\Finance\Application\OwnedResourceChanges;
use FlowBalance\Finance\Infrastructure\PostgresPaymentListReader;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PaymentListHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $request->getQueryParams();
        $limit = filter_var($parameters['limit'] ?? '20', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $month = $parameters['month'] ?? null;
        $cursor = $parameters['cursor'] ?? null;
        if ($month !== null && (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-(0[1-9]|1[0-2])\z/', $month))) {
            return new JsonResponse(['error' => ['message' => 'Selecciona un mes válido.']], 422);
        }
        $position = null;
        if ($cursor !== null && is_string($cursor) && preg_match('/\A(\d{4}-\d{2}-\d{2})~([0-9]+)\z/', $cursor, $parts)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $parts[1]);
            if ($date && $date->format('Y-m-d') === $parts[1] && $parts[1] >= '0001-01-01' && OwnedResourceChanges::identifier($parts[2])) {
                if ($month === null || str_starts_with($parts[1], $month . '-')) {
                    $position = [$parts[1], $parts[2]];
                }
            }
        }
        if ($limit === false || ($cursor !== null && $position === null)) {
            return new JsonResponse(['error' => ['message' => 'No se pudo abrir esa página de cobros.']], 422);
        }
        $reader = new PostgresPaymentListReader($request->getAttribute(PDO::class));
        return new JsonResponse($reader->read($request->getAttribute(SessionInterface::class)->get('userId'), $limit, $position, $month));
    }
}
