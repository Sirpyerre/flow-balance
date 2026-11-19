<?php

declare(strict_types=1);

use FlowBalance\Finance\Application\CreateClient;
use FlowBalance\Finance\Application\CreateProject;
use FlowBalance\Finance\Application\RecordPayment;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use PHPUnit\Framework\TestCase;

final class AccountAccessTest extends TestCase
{
    private const ORIGIN = 'https://flow-balance.test';

    public function testRegistrationLoginAndAccountIsolation(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated PostgreSQL test database');
        }
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/container.php';
        $app = $container->get(Application::class);
        (require $root . '/config/pipeline.php')($app, $container->get(MiddlewareFactory::class));
        (require $root . '/config/routes.php')($app);

        $suffix = bin2hex(random_bytes(6));
        $password = ' Una contraseña exacta ';
        $anaEmail = "ana-{$suffix}@example.com";
        $luisEmail = "luis-{$suffix}@example.com";
        [$ana, $anaCookie] = $this->register($app, 'Ana', $anaEmail, $password);
        [$luis, $luisCookie] = $this->register($app, 'Luis', $luisEmail, $password);
        $database = (require $root . '/config/database.php')->connect();

        [$emptyStatus, $empty] = $this->request($app, 'GET', '/api/dashboard', $anaCookie);
        self::assertSame(200, $emptyStatus);
        self::assertSame('0.00', $empty['clientPaidTotal']);
        self::assertSame([], $empty['projects']);

        [$duplicate] = $this->registerAttempt($app, 'Ana', strtoupper($anaEmail), $password);
        self::assertSame(422, $duplicate);
        [$login, $loginBody, $loginCookie] = $this->write($app, '/api/auth/login', [
            'email' => strtoupper($anaEmail), 'password' => $password,
        ]);
        self::assertSame(200, $login, json_encode($loginBody));
        self::assertSame($ana, $loginBody['user']['id']);

        $unknownEmail = "unknown-{$suffix}@example.com";
        foreach ([$anaEmail, $unknownEmail] as $email) {
            for ($attempt = 1; $attempt <= 5; $attempt++) {
                [$failed, $failure] = $this->write($app, '/api/auth/login', [
                    'email' => $email, 'password' => 'incorrecta',
                ]);
                self::assertSame($attempt === 5 ? 429 : 401, $failed, json_encode($failure));
                self::assertSame($attempt === 5 ? 'login_temporarily_blocked' : 'invalid_credentials', $failure['error']['code']);
            }
        }
        [$blocked] = $this->write($app, '/api/auth/login', [
            'email' => strtoupper($anaEmail), 'password' => $password,
        ]);
        self::assertSame(429, $blocked);

        $anaPayment = $this->payment($database, $ana, '3000.00');
        $this->payment($database, $luis, '800.00');
        $report = '/api/reports/monthly?month=2026-11&currency=MXN';
        foreach ([$loginCookie => '3000.00', $luisCookie => '800.00'] as $cookie => $expected) {
            [$status, $body] = $this->request($app, 'GET', $report, $cookie);
            self::assertSame(200, $status, json_encode($body));
            self::assertSame($expected, $body['clientPaidTotal']);
            self::assertCount(1, $body['payments']);
        }
        [$foreignRead] = $this->request($app, 'GET', "/api/payments/{$anaPayment}", $luisCookie);
        self::assertSame(404, $foreignRead);
        [$otherMonth, $zero] = $this->request($app, 'GET', '/api/reports/monthly?month=2026-12&currency=MXN', $luisCookie);
        self::assertSame(200, $otherMonth);
        self::assertSame('0.00', $zero['clientPaidTotal']);
        [$denied, $deniedBody] = $this->write($app, "/api/payments/{$anaPayment}", [
            'clientPaidAmount' => '1.00',
        ], $luisCookie, 'PATCH');
        self::assertSame(404, $denied, json_encode($deniedBody));
        [$status, $body] = $this->request($app, 'GET', "/api/payments/{$anaPayment}", $anaCookie);
        self::assertSame(200, $status);
        self::assertSame('3000.000000', $body['clientPaidAmount']);
        [$unverifiedLogout] = $this->request($app, 'POST', '/api/auth/logout', $anaCookie, []);
        self::assertSame(403, $unverifiedLogout);
        [$logout] = $this->write($app, '/api/auth/logout', [], $anaCookie);
        self::assertSame(204, $logout);
        foreach (['/api/auth/session', '/api/dashboard', $report] as $path) {
            [$replay] = $this->request($app, 'GET', $path, $anaCookie);
            self::assertSame(401, $replay);
        }
        [$independent] = $this->request($app, 'GET', '/api/auth/session', $loginCookie);
        self::assertSame(200, $independent);
        foreach (['payments', 'projects', 'clients', 'server_sessions'] as $table) {
            $database->prepare("DELETE FROM {$table} WHERE user_id IN (?, ?)")->execute([$ana, $luis]);
        }
        $database->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$ana, $luis]);
    }

    public function testIncomeCaptureRetryAndSeparateCharges(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated PostgreSQL test database');
        }
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/container.php';
        $app = $container->get(Application::class);
        (require $root . '/config/pipeline.php')($app, $container->get(MiddlewareFactory::class));
        (require $root . '/config/routes.php')($app);
        $suffix = bin2hex(random_bytes(6));
        [$ana, $cookie] = $this->register($app, 'Ana', "income-{$suffix}@example.com", 'Una contraseña exacta');
        [$luis, $other] = $this->register($app, 'Luis', "other-{$suffix}@example.com", 'Una contraseña exacta');
        $database = (require $root . '/config/database.php')->connect();

        try {
            [$status, $client] = $this->write($app, '/api/clients', ['name' => ' Estudio Norte '], $cookie);
            self::assertSame(201, $status, json_encode($client));
            $input = ['clientId' => $client['id'], 'name' => 'Identidad visual', 'agreedAmount' => '5000',
                'agreedCurrency' => 'MXN', 'agreedPaymentCount' => 3, 'acquisitionSource' => 'Recomendación'];
            self::assertSame(404, $this->write($app, '/api/projects', $input, $other)[0]);
            self::assertSame(422, $this->write($app, '/api/projects', [...$input, 'agreedCurrency' => 'USD'], $cookie)[0]);
            [$status, $project] = $this->write($app, '/api/projects', $input, $cookie);
            self::assertSame(201, $status, json_encode($project));
            self::assertSame(0, $project['paymentCount']);
            self::assertSame(3, $project['agreedPaymentCount']);
            $payment = ['projectId' => $project['id'], 'paidOn' => '2025-11-30', 'currency' => 'MXN',
                'sourceType' => 'direct_client', 'clientPaidAmount' => '4800', 'platformFee' => '150.25', 'withheldTaxAmount' => '49.75'];
            [$status, $preview] = $this->write($app, '/api/payments/preview', $payment, $cookie);
            self::assertSame(200, $status, json_encode($preview));
            self::assertSame('4600.00', $preview['receivedAmount']);
            self::assertSame(404, $this->write($app, '/api/payments', $payment, $other, key: 'first')[0]);
            self::assertSame(422, $this->write($app, '/api/payments', [...$payment, 'currency' => 'USD'], $cookie, key: 'first')[0]);
            [$status, $first] = $this->write($app, '/api/payments', $payment, $cookie, key: 'first');
            self::assertSame(201, $status, json_encode($first));
            $second = [...$payment, 'clientPaidAmount' => '500', 'platformFee' => '0', 'withheldTaxAmount' => '0', 'paidOn' => '2025-12-01',
                'sourceType' => 'platform', 'sourceName' => 'Upwork'];
            [$status, $missing] = $this->write($app, '/api/payments', $second, $cookie, key: 'second');
            self::assertSame(422, $status, json_encode($missing));
            self::assertSame('300.00', $missing['excessAmount']);
            $third = [...$second, 'clientPaidAmount' => '300', 'excessReason' => 'Pago adicional'];
            self::assertSame(201, $this->write($app, '/api/payments', $third, $cookie, key: 'third')[0]);
            [$status, $saved] = $this->write($app, '/api/payments', [...$second, 'excessReason' => 'Trabajo adicional'], $cookie, key: 'second');
            self::assertSame(201, $status, json_encode($saved));
            self::assertSame('600.00', $saved['excessAmount']);
            self::assertSame('Trabajo adicional', $saved['excessReason']);
            [$status, $replay] = $this->write($app, '/api/payments', $payment, $cookie, key: 'first');
            self::assertSame(200, $status, json_encode($replay));
            self::assertSame($first['id'], $replay['id']);
            self::assertSame(409, $this->write($app, '/api/payments', [...$payment, 'paidOn' => '2025-12-01'], $cookie, key: 'first')[0]);

            $path = "/api/payments/{$first['id']}";
            $charge = ['chargeType' => 'commission', 'paidOn' => '2025-12-02', 'amount' => '50.00', 'currency' => 'MXN'];
            self::assertSame(404, $this->write($app, "$path/charges", $charge, $other, key: 'first')[0]);
            self::assertSame(422, $this->write($app, "$path/charges", [...$charge, 'currency' => 'USD'], $cookie, key: 'first')[0]);
            self::assertSame(201, $this->write($app, "$path/charges", $charge, $cookie, key: 'first')[0]);
            self::assertSame(200, $this->write($app, "$path/charges", $charge, $cookie, key: 'first')[0]);
            self::assertSame(409, $this->write($app, "$path/charges", [...$charge, 'amount' => '25'], $cookie, key: 'first')[0]);
            self::assertSame(201, $this->write($app, "$path/charges", [...$charge, 'chargeType' => 'tax', 'paidOn' => '2025-12-03'], $cookie, key: 'tax')[0]);
            [$status, $detail] = $this->request($app, 'GET', $path, $cookie);
            self::assertSame(200, $status);
            self::assertSame('4600.00', $detail['receivedAmount']);
            self::assertSame(['2025-12-02', '2025-12-03'], array_column($detail['charges'], 'paidOn'));
            self::assertSame(422, $this->write($app, $path, ['clientPaidAmount' => '100'], $cookie, 'PATCH')[0]);
            self::assertSame(422, $this->write($app, $path, ['clientPaidAmount' => '4900'], $cookie, 'PATCH')[0]);
            self::assertSame(404, $this->request($app, 'GET', $path, $other)[0]);
            $report = '/api/reports/monthly?month=2025-11&currency=MXN';
            self::assertSame('4800.00', $this->request($app, 'GET', $report, $cookie)[1]['clientPaidTotal']);
            self::assertSame('0.00', $this->request($app, 'GET', $report, $other)[1]['clientPaidTotal']);
            self::assertSame('800.00', $this->request($app, 'GET', '/api/reports/monthly?month=2025-12&currency=MXN', $cookie)[1]['clientPaidTotal']);
            [, $updated] = $this->request($app, 'GET', "/api/projects/{$project['id']}", $cookie);
            self::assertSame(3, $updated['paymentCount']);
            self::assertSame('5600.00', $updated['clientPaidTotal']);
            foreach (['agreedAmount', 'agreedPaymentCount', 'acquisitionSource', 'name', 'clientId'] as $field) {
                self::assertSame($project[$field], $updated[$field]);
            }
        } finally {
            foreach (['payment_charges', 'payments', 'projects', 'clients', 'server_sessions'] as $table) {
                $database->prepare("DELETE FROM {$table} WHERE user_id IN (?, ?)")->execute([$ana, $luis]);
            }
            $database->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$ana, $luis]);
        }
    }

    private function payment(PDO $database, string $userId, string $amount): string
    {
        $client = (new CreateClient($database))->create($userId, 'Cliente');
        $project = (new CreateProject($database))->create($userId, $client, 'Proyecto');
        $service = new RecordPayment($database);
        $payment = $service->record($userId, $project, $amount, 'MXN', '2026-11-10', 'acceptance-payment');
        self::assertSame($payment, $service->record($userId, $project, $amount, 'MXN', '2026-11-10', 'acceptance-payment'));
        return $payment;
    }

    private function register(Application $app, string $name, string $email, string $password): array
    {
        [$status, $body, $cookie] = $this->registerAttempt($app, $name, $email, $password);
        self::assertSame(201, $status, json_encode($body));
        return [$body['user']['id'], $cookie];
    }

    private function registerAttempt(Application $app, string $name, string $email, string $password): array
    {
        return $this->write($app, '/api/auth/register', [
            'name' => $name, 'email' => $email, 'password' => $password, 'passwordConfirmation' => $password,
        ]);
    }

    private function write(Application $app, string $path, array $data, string $cookie = '', string $method = 'POST', string $key = ''): array
    {
        [$status, $challenge, $cookie] = $this->request($app, 'GET', '/api/auth/csrf', $cookie);
        self::assertSame(200, $status, json_encode($challenge));
        return $this->request($app, $method, $path, $cookie, $data, $challenge['csrfToken'], $key);
    }

    private function request(Application $app, string $method, string $path, string $cookie, ?array $data = null, string $csrf = '', string $key = ''): array
    {
        $body = new Stream('php://temp', 'wb+');
        if ($data !== null) {
            $body->write(json_encode((object) $data, JSON_THROW_ON_ERROR));
            $body->rewind();
        }
        $request = new ServerRequest(['REMOTE_ADDR' => '127.0.0.1'], [], self::ORIGIN . $path, $method, $body);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);
        if ($cookie !== '') {
            $request = $request->withCookieParams(['__Host-flow_balance' => $cookie]);
        }
        if ($data !== null) {
            $request = $request->withHeader('Origin', self::ORIGIN)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-CSRF-Token', $csrf);
        }
        if ($key !== '') {
            $request = $request->withHeader('Idempotency-Key', $key);
        }
        $response = $app->handle($request);
        if (preg_match('/^__Host-flow_balance=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match)) {
            $cookie = $match[1];
        }
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $cookie];
    }
}
