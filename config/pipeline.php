<?php

declare(strict_types=1);

use Laminas\Diactoros\Response\JsonResponse;
use Laminas\Stratigility\Middleware\ErrorHandler;
use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\RouteMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return static function (Application $app, MiddlewareFactory $factory): void {
    $app->pipe($factory->prepare(static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        return $next->handle($request)->withHeader('Cache-Control', 'no-store, private');
    }));
    $app->pipe(ErrorHandler::class);
    $app->pipe(RouteMiddleware::class);
    $app->pipe(DispatchMiddleware::class);
    $app->pipe($factory->prepare(static fn (ServerRequestInterface $request, RequestHandlerInterface $next) =>
        new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Recurso no disponible']], 404)
    ));
};
