<?php

declare(strict_types=1);

use FlowBalance\Finance\Infrastructure\SqlAccountDataReader;
use FlowBalance\Finance\Http\MonthlyReportHandler;
use FlowBalance\Finance\Http\OwnedResourceHandler;
use FlowBalance\Finance\Http\PaymentCorrectionHandler;
use FlowBalance\Finance\Http\PaymentListHandler;
use FlowBalance\Finance\Http\ProjectBalanceReportHandler;
use FlowBalance\Finance\Http\IncomeWriteHandler;
use FlowBalance\Identity\Application\LoginUser;
use FlowBalance\Identity\Application\RegisterUser;
use FlowBalance\Identity\Domain\SessionPolicy;
use FlowBalance\Identity\Http\AuthRequestMiddleware;
use FlowBalance\Identity\Http\LoginHandler;
use FlowBalance\Identity\Http\LogoutHandler;
use FlowBalance\Identity\Http\RegistrationHandler;
use FlowBalance\Identity\Infrastructure\NativePasswordHasher;
use FlowBalance\Identity\Infrastructure\SqlSessionPersistence;
use FlowBalance\Shared\Clock\SystemClock;
use FlowBalance\Shared\Configuration\AppSettings;
use FlowBalance\Shared\Configuration\InvalidConfiguration;
use FlowBalance\Shared\Database\UnitOfWork;
use FlowBalance\Shared\Http\ClientIpResolver;
use FlowBalance\Shared\Http\HealthCheck;
use Laminas\Diactoros\Response\JsonResponse;
use Mezzio\Application;
use Mezzio\Csrf\SessionCsrfGuard;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return static function (Application $app): void {
    $settings = static fn (): AppSettings => require __DIR__ . '/settings.php';
    $transaction = static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($settings) {
        try {
            $settings();
            $config = require __DIR__ . '/database.php';
            $database = $config->connect();
            $clock = new SystemClock();
            $work = new UnitOfWork($database);
            $session = new SessionMiddleware(new SqlSessionPersistence($database, $clock, new SessionPolicy()));
            return $work->transaction(static fn () => $session->process(
                $request->withAttribute(PDO::class, $database)->withAttribute(UnitOfWork::class, $work),
                $handler,
            ));
        } catch (InvalidConfiguration $error) {
            error_log('Flow Balance configuration failure: ' . $error->getMessage());
            return new JsonResponse(['error' => [
                'code' => 'configuration_unavailable', 'message' => 'Intenta de nuevo más tarde',
            ]], 503);
        } catch (PDOException $error) {
            error_log('Flow Balance database failure: ' . $error->getCode());
            return new JsonResponse(['error' => [
                'code' => 'persistence_unavailable', 'message' => 'Intenta de nuevo más tarde',
            ]], 503);
        }
    };

    // Outside $transaction on purpose: no session, no transaction, nothing written (design D16).
    $app->get('/api/health', new HealthCheck($settings, static fn (): PDO => (require __DIR__ . '/database.php')->connect()));

    $app->get('/api/auth/csrf', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $guard = new SessionCsrfGuard($request->getAttribute(SessionInterface::class));
        return new JsonResponse(['csrfToken' => $guard->generateToken()]);
    }]);

    $app->post('/api/auth/register', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($settings) {
        $origin = $settings()->origin;
        $service = new RegisterUser(
            $request->getAttribute(PDO::class),
            $request->getAttribute(UnitOfWork::class),
            new NativePasswordHasher(),
        );
        return (new AuthRequestMiddleware($origin))->process(
            $request, new RegistrationHandler($service, new SystemClock()),
        );
    }]);
    $app->post('/api/auth/login', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($settings) {
        $current = $settings();
        $secret = $current->secret();
        if ($secret === null) {
            return new JsonResponse(['error' => ['code' => 'configuration_unavailable', 'message' => 'Intenta de nuevo más tarde']], 503);
        }
        $service = new LoginUser($request->getAttribute(PDO::class), new NativePasswordHasher(), $secret);
        return (new AuthRequestMiddleware($current->origin))->process($request, new LoginHandler($service, new SystemClock(), new ClientIpResolver(
                $current->trustedProxyPeers,
                $current->proxyToken,
            )));
    }]);
    $app->get('/api/auth/session', [$transaction, static function (ServerRequestInterface $request, RequestHandlerInterface $next) {
        $session = $request->getAttribute(SessionInterface::class);
        $userId = $session->get('userId');
        if ($userId === null) {
            return new JsonResponse(['error' => [
                'code' => 'authentication_required', 'message' => 'Inicia sesión para continuar',
            ]], 401);
        }
        $query = $request->getAttribute(PDO::class)->prepare('SELECT CAST(id AS CHAR) AS id, name, email FROM users WHERE id = ?');
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
            $reader = new SqlAccountDataReader($request->getAttribute(PDO::class));
            return new JsonResponse($reader->read($userId, $month));
        },
    ]);
    $app->post('/api/auth/logout', [$transaction, $private,
        static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($settings) {
            $origin = $settings()->origin;
            return (new AuthRequestMiddleware($origin, requireAnonymous: false))->process($request, new LogoutHandler());
        },
    ]);
    $app->get('/api/payments', [$transaction, $private, new PaymentListHandler()]);
    $app->get('/api/reports/project-balances', [$transaction, $private, new ProjectBalanceReportHandler()]);
    $app->get('/api/reports/monthly', [$transaction, $private, new MonthlyReportHandler()]);
    $app->get('/api/reports/monthly/export', [$transaction, $private, new MonthlyReportHandler(export: true)]);
    foreach ([
        '/api/clients' => 'client',
        '/api/projects' => 'project',
        '/api/projects/{id}/agreement-preview' => 'agreement-preview',
        '/api/payments/preview' => 'preview',
        '/api/payments' => 'payment',
        '/api/payments/{id}/charges' => 'charge',
    ] as $path => $operation) {
        $app->post($path, [$transaction, $private,
            static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($operation, $settings) {
                $origin = $settings()->origin;
                return (new AuthRequestMiddleware($origin, requireAnonymous: false))->process($request, new IncomeWriteHandler($operation));
            },
        ]);
    }
    $correctPayment = static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($settings) {
        $origin = $settings()->origin;
        return (new AuthRequestMiddleware($origin, requireAnonymous: false))->process($request, new PaymentCorrectionHandler());
    };
    $app->post('/api/payments/{id}/preview', [$transaction, $private, $correctPayment], 'payments.preview-edit');
    $app->patch('/api/payments/{id}', [$transaction, $private, $correctPayment], 'payments.patch');
    $app->delete('/api/payments/{id}', [$transaction, $private, $correctPayment], 'payments.delete');
    foreach (['clients', 'projects', 'payments'] as $type) {
        $handler = new OwnedResourceHandler($type);
        $app->get("/api/{$type}/{id}", [$transaction, $private, $handler], "{$type}.get");
        if ($type === 'payments') {
            continue;
        }
        $app->patch("/api/{$type}/{id}", [$transaction, $private,
            static function (ServerRequestInterface $request, RequestHandlerInterface $next) use ($handler, $settings) {
                $origin = $settings()->origin;
                return (new AuthRequestMiddleware($origin, requireAnonymous: false))->process($request, $handler);
            },
        ], "{$type}.patch");
    }
};
