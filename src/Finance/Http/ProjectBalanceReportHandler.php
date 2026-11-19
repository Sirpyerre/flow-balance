<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use FlowBalance\Finance\Infrastructure\PostgresProjectBalanceReportReader;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Session\SessionInterface;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ProjectBalanceReportHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $reader = new PostgresProjectBalanceReportReader($request->getAttribute(PDO::class));
        return new JsonResponse($reader->read($request->getAttribute(SessionInterface::class)->get('userId')));
    }
}
