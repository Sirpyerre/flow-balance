<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Http;

use FlowBalance\Finance\Infrastructure\SqlProjectBalanceReportReader;
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
        $reader = new SqlProjectBalanceReportReader($request->getAttribute(PDO::class));
        return new JsonResponse($reader->read($request->getAttribute(SessionInterface::class)->get('userId')));
    }
}
