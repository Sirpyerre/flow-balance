<?php

declare(strict_types=1);

use FlowBalance\Finance\Infrastructure\PostgresAccountDataReader;
use FlowBalance\Finance\Infrastructure\PostgresMonthlyReportReader;
use FlowBalance\Finance\Http\OwnedResourceHandler;
use FlowBalance\Identity\Application\LoginUser;
use FlowBalance\Identity\Application\RegisterUser;
use FlowBalance\Identity\Domain\SessionPolicy;
use FlowBalance\Identity\Http\AuthRequestMiddleware;
use FlowBalance\Identity\Http\LoginHandler;
use FlowBalance\Identity\Http\LogoutHandler;
use FlowBalance\Identity\Http\RegistrationHandler;
use FlowBalance\Identity\Infrastructure\NativePasswordHasher;
use FlowBalance\Identity\Infrastructure\PostgresSessionPersistence;
use FlowBalance\Shared\Clock\SystemClock;
use FlowBalance\Shared\Database\UnitOfWork;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Application;
use Mezzio\Csrf\SessionCsrfGuard;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return static function (Application $app): void {
    $transaction = static function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
        try {
            $config = require __DIR__ . '/database.php';
            $database = $config->connect();
            $clock = new SystemClock();
            $work = new UnitOfWork($database);
            $session = new SessionMiddleware(new PostgresSessionPersistence($database, $clock, new SessionPolicy()));
            return $work->transaction(static fn () => $session->process(
                $request->withAttribute(PDO::class, $database)->withAttribute(UnitOfWork::class, $work),
                $handler,
            ));
        } catch (PDOException $error) {
            error_log('Flow Balance database failure: ' . $error->getCode());
            return new JsonResponse(['error' => [
                'code' => 'persistence_unavailable', 'message' => 'Intenta de nuevo más tarde',
            ]], 503);
        }
    };

    $app->get('/api/auth/csrf', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $guard = new SessionCsrfGuard($request->getAttribute(SessionInterface::class));
        return new JsonResponse(['csrfToken' => $guard->generateToken()]);
    }]);

    $app->post('/api/auth/register', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $origin = getenv('APP_ORIGIN') ?: ($_ENV['APP_ORIGIN'] ?? 'https://flow-balance.test');
        $service = new RegisterUser(
            $request->getAttribute(PDO::class),
            $request->getAttribute(UnitOfWork::class),
            new NativePasswordHasher(),
        );
        return (new AuthRequestMiddleware($origin))->process(
            $request, new RegistrationHandler($service, new SystemClock()),
        );
    }]);
    $app->post('/api/auth/login', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $origin = getenv('APP_ORIGIN') ?: ($_ENV['APP_ORIGIN'] ?? 'https://flow-balance.test');
        $secretKey = (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'development')) === 'testing' ? 'TEST_APP_SECRET' : 'APP_SECRET';
        $secret = getenv($secretKey) ?: ($_ENV[$secretKey] ?? '');
        if (strlen($secret) < 32) {
            return new JsonResponse(['error' => ['code' => 'configuration_unavailable', 'message' => 'Intenta de nuevo más tarde']], 503);
        }
        $service = new LoginUser($request->getAttribute(PDO::class), new NativePasswordHasher(), $secret);
        return (new AuthRequestMiddleware($origin))->process($request, new LoginHandler($service, new SystemClock()));
    }]);
    $app->get('/api/auth/session', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $session = $request->getAttribute(SessionInterface::class);
        $userId = $session->get('userId');
        if ($userId === null) {
            return new JsonResponse(['error' => [
                'code' => 'authentication_required', 'message' => 'Inicia sesión para continuar',
            ]], 401);
        }
        $query = $request->getAttribute(PDO::class)->prepare('SELECT id::text, name, email FROM users WHERE id = ?');
        $query->execute([$userId]);
        $authenticatedAt = $session->get('authenticatedAt');
        return new JsonResponse(['user' => $query->fetch(PDO::FETCH_ASSOC), 'session' => [
            'idleExpiresAt' => gmdate(DATE_ATOM, (new SessionPolicy())->idleDeadline($authenticatedAt, $session->get('lastActivityAt'))),
            'absoluteExpiresAt' => gmdate(DATE_ATOM, $authenticatedAt + SessionPolicy::ABSOLUTE_SECONDS),
        ]]);
    }]);
    $private = static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $session = $request->getAttribute(SessionInterface::class);
        if (!$session->has('userId')) {
            return new JsonResponse(['error' => ['code' => 'authentication_required', 'message' => 'Inicia sesión para continuar']], 401);
        }
        if (array_key_exists('userId', $request->getQueryParams())) {
            return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Revisa los campos indicados']], 422);
        }
        $response = $next->handle($request);
        if ($response->getStatusCode() === 204 || $response->getStatusCode() >= 400) {
            return $response;
        }
        $now = $session->get('lastActivityAt');
        if ($request->getMethod() !== 'GET' || $request->getHeaderLine('X-Session-Activity') !== 'passive') {
            $now = time();
            $session->set('lastActivityAt', $now);
        }
        return $response->withHeader('X-Session-Idle-Expires-At',
            gmdate(DATE_ATOM, (new SessionPolicy())->idleDeadline($session->get('authenticatedAt'), $now)));
    };
    $app->get('/api/dashboard', [$transaction, $private,
        static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
            $userId = $request->getAttribute(SessionInterface::class)->get('userId');
            $month = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m');
            $reader = new PostgresAccountDataReader($request->getAttribute(PDO::class));
            return new JsonResponse($reader->read($userId, $month));
        },
    ]);
    $app->post('/api/auth/logout', [$transaction, $private,
        static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
            $origin = getenv('APP_ORIGIN') ?: ($_ENV['APP_ORIGIN'] ?? 'https://flow-balance.test');
            return (new AuthRequestMiddleware($origin, requireAnonymous: false))->process($request, new LogoutHandler());
        },
    ]);
    $app->get('/api/reports/monthly', [$transaction, $private,
        static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
            $parameters = $request->getQueryParams();
            $month = $parameters['month'] ?? '';
            $currency = $parameters['currency'] ?? '';
            if (!is_string($month) || !preg_match('/\A[1-9][0-9]{3}-(0[1-9]|1[0-2])\z/', $month) || $currency !== 'MXN') {
                return new JsonResponse(['error' => ['code' => 'validation_failed', 'message' => 'Revisa el mes y la moneda']], 422);
            }
            $reader = new PostgresMonthlyReportReader($request->getAttribute(PDO::class));
            return new JsonResponse($reader->read($request->getAttribute(SessionInterface::class)->get('userId'), $month, $currency));
        },
    ]);
    foreach (['clients', 'projects', 'payments'] as $type) {
        $handler = new OwnedResourceHandler($type);
        $app->get("/api/{$type}/{id}", [$transaction, $private, $handler], "{$type}.get");
        $app->patch("/api/{$type}/{id}", [$transaction, $private,
            static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($handler) {
                $origin = getenv('APP_ORIGIN') ?: ($_ENV['APP_ORIGIN'] ?? 'https://flow-balance.test');
                return (new AuthRequestMiddleware($origin, requireAnonymous: false))->process($request, $handler);
            },
        ], "{$type}.patch");
    }
};
