<?php

declare(strict_types=1);

use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use PHPUnit\Framework\TestCase;

/**
 * Pins the whole API surface. The deployment change added exactly one route, GET /api/health; every other path and
 * method existed before it. Adding, removing or renaming a route fails here until the list is changed on purpose.
 */
final class RouteTableTest extends TestCase
{
    private const EXPECTED = [
        'DELETE  /api/payments/{id}',
        'GET     /api/auth/csrf',
        'GET     /api/auth/session',
        'GET     /api/clients/{id}',
        'GET     /api/dashboard',
        'GET     /api/health',
        'GET     /api/payments',
        'GET     /api/payments/{id}',
        'GET     /api/projects/{id}',
        'GET     /api/reports/monthly',
        'GET     /api/reports/monthly/export',
        'GET     /api/reports/project-balances',
        'PATCH   /api/clients/{id}',
        'PATCH   /api/payments/{id}',
        'PATCH   /api/projects/{id}',
        'POST    /api/auth/login',
        'POST    /api/auth/logout',
        'POST    /api/auth/register',
        'POST    /api/clients',
        'POST    /api/payments',
        'POST    /api/payments/preview',
        'POST    /api/payments/{id}/charges',
        'POST    /api/payments/{id}/preview',
        'POST    /api/projects',
        'POST    /api/projects/{id}/agreement-preview',
    ];

    public function testTheApiExposesExactlyTheseRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/container.php';
        $app = $container->get(Application::class);
        (require $root . '/config/pipeline.php')($app, $container->get(MiddlewareFactory::class));
        (require $root . '/config/routes.php')($app);

        $routes = array_map(
            static fn ($route): string => sprintf('%-7s %s', implode(',', $route->getAllowedMethods() ?? ['*']), $route->getPath()),
            $app->getRoutes(),
        );
        sort($routes);

        self::assertSame(self::EXPECTED, $routes);
    }
}
