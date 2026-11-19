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
            self::assertSame('Estudio Norte', $client['name']);
            $input = ['clientId' => $client['id'], 'name' => 'Identidad visual', 'agreedAmount' => '5000',
                'agreedCurrency' => 'MXN', 'agreedPaymentCount' => 3, 'acquisitionSource' => 'Recomendación'];
            self::assertSame(404, $this->write($app, '/api/projects', $input, $other)[0]);
            self::assertSame(
                $this->write($app, '/api/projects', [...$input, 'clientId' => '9223372036854775807'], $other)[1],
                $this->write($app, '/api/projects', $input, $other)[1],
            );
            self::assertSame(401, $this->write($app, '/api/projects', $input)[0]);
            self::assertSame(403, $this->request($app, 'POST', '/api/projects', $cookie, $input)[0]);
            foreach ([0, -1, 1.5, '3'] as $invalidCount) {
                self::assertSame(422, $this->write($app, '/api/projects', [...$input, 'agreedPaymentCount' => $invalidCount], $cookie)[0]);
            }
            self::assertSame(422, $this->write($app, '/api/projects', [...$input, 'agreedCurrency' => 'USD'], $cookie)[0]);
            [$status, $project] = $this->write($app, '/api/projects', $input, $cookie);
            self::assertSame(201, $status, json_encode($project));
            self::assertSame(0, $project['paymentCount']);
            self::assertSame(3, $project['agreedPaymentCount']);
            self::assertSame('5000.00', $project['agreedAmount']);
            self::assertSame('Recomendación', $project['acquisitionSource']);
            $legacyId = (new CreateProject($database))->create($ana, $client['id'], 'Proyecto histórico');
            [$legacyStatus, $legacy] = $this->request($app, 'GET', "/api/projects/{$legacyId}", $cookie);
            self::assertSame(200, $legacyStatus);
            self::assertNull($legacy['agreedAmount']);
            self::assertNull($legacy['agreedPaymentCount']);
            $payment = ['projectId' => $project['id'], 'paidOn' => '2025-11-30', 'currency' => 'MXN',
                'sourceType' => 'direct_client', 'clientPaidAmount' => '4800', 'platformFee' => '150.25', 'withheldTaxAmount' => '49.75'];
            [$status, $preview] = $this->write($app, '/api/payments/preview', $payment, $cookie);
            self::assertSame(200, $status, json_encode($preview));
            self::assertSame('4600.00', $preview['receivedAmount']);
            self::assertSame(0, $this->request($app, 'GET', "/api/projects/{$project['id']}", $cookie)[1]['paymentCount']);
            foreach ([
                ['paidOn' => '2025-02-30'],
                ['sourceType' => 'platform'],
                ['platformFee' => '5000'],
            ] as $invalidPayment) {
                self::assertSame(422, $this->write($app, '/api/payments', [...$payment, ...$invalidPayment], $cookie, key: 'first')[0]);
            }
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
            self::assertSame(
                $this->write($app, '/api/payments/9223372036854775807/charges', $charge, $other, key: 'first')[1],
                $this->write($app, "$path/charges", $charge, $other, key: 'first')[1],
            );
            self::assertSame(401, $this->write($app, "$path/charges", $charge, key: 'first')[0]);
            self::assertSame(403, $this->request($app, 'POST', "$path/charges", $cookie, $charge, key: 'first')[0]);
            foreach ([
                ['paidOn' => '2025-02-30'],
                ['chargeType' => 'fee'],
                ['amount' => '0'],
                ['amount' => '1.001'],
            ] as $invalidCharge) {
                self::assertSame(422, $this->write($app, "$path/charges", [...$charge, ...$invalidCharge], $cookie, key: 'first')[0]);
            }
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

    public function testProjectBalanceDetailAndReportUseTheSameOwnedPayments(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated PostgreSQL test database');
        }
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/container.php';
        $app = $container->get(Application::class);
        (require $root . '/config/pipeline.php')($app, $container->get(MiddlewareFactory::class));
        (require $root . '/config/routes.php')($app);
        $suffix = bin2hex(random_bytes(8));
        [$ana, $cookie] = $this->register($app, 'Ana', "balance-{$suffix}@example.com", 'Una contraseña exacta');
        [$luis, $other] = $this->register($app, 'Luis', "other-balance-{$suffix}@example.com", 'Una contraseña exacta');
        $database = (require $root . '/config/database.php')->connect();
        $writer = (require $root . '/config/database.php')->connect();

        try {
            $reportPath = '/api/reports/project-balances';
            self::assertSame(401, $this->request($app, 'GET', $reportPath, '')[0]);
            self::assertSame(['projects' => []], $this->request($app, 'GET', $reportPath, $other)[1]);

            $client = (new CreateClient($database))->create($ana, 'Estudio Norte');
            $projects = new CreateProject($database);
            $agreement = ['5000.00', 'MXN', 3, 'Recomendación'];
            $project = $projects->create($ana, $client, 'Alfa', $agreement);
            $sameName = $projects->create($ana, $client, 'Alfa', $agreement);
            $historic = $projects->create($ana, $client, 'Histórico');
            $fraction = $projects->create($ana, $client, 'Fracciones', ['0.30', 'MXN', 1, 'Directo']);
            $detailPath = "/api/projects/{$project}";
            self::assertSame('5000.00', $this->request($app, 'GET', $detailPath, $cookie)[1]['outstandingBalance']);
            self::assertSame($this->request($app, 'GET', '/api/projects/9223372036854775807', $other),
                $this->request($app, 'GET', $detailPath, $other));

            $statement = $database->prepare("INSERT INTO payments (user_id,project_id,client_paid_amount,currency,paid_on,idempotency_key,request_fingerprint)
                VALUES (?,?,'0.015','MXN','2025-11-30',?,repeat('a',64))");
            $statement->execute([$ana, $fraction, 'fraction-1']);
            $statement->execute([$ana, $fraction, 'fraction-2']);
            $rounded = $this->request($app, 'GET', "/api/projects/{$fraction}", $cookie)[1];
            self::assertSame('0.04', $rounded['clientPaidTotal']);
            self::assertSame('0.26', $rounded['outstandingBalance']);
            self::assertSame(['0.02', '0.02'], array_column($rounded['payments'], 'clientPaidAmount'));
            $raw = $database->prepare('SELECT DISTINCT client_paid_amount::text FROM payments WHERE project_id = ?');
            $raw->execute([$fraction]);
            self::assertSame(['0.015000'], $raw->fetchAll(PDO::FETCH_COLUMN));

            $input = ['projectId' => $project, 'paidOn' => '2025-11-30', 'currency' => 'MXN',
                'sourceType' => 'direct_client', 'clientPaidAmount' => '3000', 'platformFee' => '150', 'withheldTaxAmount' => '50'];
            [$status, $payment] = $this->write($app, '/api/payments', $input, $cookie, key: 'first');
            self::assertSame(201, $status);
            [$status, $replay] = $this->write($app, '/api/payments', $input, $cookie, key: 'first');
            self::assertSame(200, $status);
            self::assertSame($payment['id'], $replay['id']);
            $charge = ['chargeType' => 'commission', 'paidOn' => '2025-12-01', 'amount' => '20', 'currency' => 'MXN'];
            self::assertSame(201, $this->write($app, "/api/payments/{$payment['id']}/charges", $charge, $cookie, key: 'charge')[0]);

            $writer->beginTransaction();
            (new RecordPayment($writer))->record($ana, $project, '800', 'MXN', '2025-12-01', 'second');
            $beforeCommit = $this->request($app, 'GET', $detailPath, $cookie)[1];
            self::assertSame('2000.00', $beforeCommit['outstandingBalance']);
            self::assertSame(['3000.00'], array_column($beforeCommit['payments'], 'clientPaidAmount'));
            $writer->commit();
            $afterCommit = $this->request($app, 'GET', $detailPath, $cookie)[1];
            self::assertSame('1200.00', $afterCommit['outstandingBalance']);
            self::assertSame(['800.00', '3000.00'], array_column($afterCommit['payments'], 'clientPaidAmount'));

            $historicalPayments = $database->prepare("INSERT INTO payments
                (user_id, project_id, client_paid_amount, currency, paid_on, idempotency_key, request_fingerprint, deleted_at)
                VALUES (?, ?, '100', ?, '2025-11-30', ?, repeat('b', 64), ?)");
            $historicalPayments->execute([$ana, $project, 'USD', 'historical-other-currency', null]);
            $historicalPayments->execute([$ana, $project, 'MXN', 'historical-deleted', '2025-12-01']);
            [$status, $summary, , $headers] = $this->request($app, 'GET', $detailPath, $cookie);
            self::assertSame(200, $status);
            self::assertStringContainsString('no-store', $headers);
            self::assertSame(3, $summary['paymentCount']);
            self::assertSame(3, $summary['agreedPaymentCount']);
            self::assertSame($afterCommit['outstandingBalance'], $summary['outstandingBalance']);
            self::assertSame($afterCommit['payments'], $summary['payments']);

            [$status, $report, , $headers] = $this->request($app, 'GET', $reportPath, $cookie);
            self::assertSame(200, $status);
            self::assertStringContainsString('no-store', $headers);
            self::assertSame([$project, $sameName, $fraction, $historic], array_column($report['projects'], 'projectId'));
            foreach (['outstandingBalance', 'excessAmount', 'clientPaidTotal', 'collectionStatus'] as $field) {
                self::assertSame($afterCommit[$field], $report['projects'][0][$field]);
            }
            self::assertSame('undetermined', $report['projects'][3]['collectionStatus']);
            self::assertNull($report['projects'][3]['outstandingBalance']);
            self::assertSame('0.04', $report['projects'][2]['clientPaidTotal']);
            self::assertSame('0.26', $report['projects'][2]['outstandingBalance']);
            $otherClient = (new CreateClient($database))->create($luis, 'Otro cliente');
            $otherProject = $projects->create($luis, $otherClient, 'Proyecto de Luis', $agreement);
            self::assertSame([$otherProject], array_column($this->request($app, 'GET', $reportPath, $other)[1]['projects'], 'projectId'));
            self::assertSame([$project, $sameName, $fraction, $historic], array_column(
                $this->request($app, 'GET', $reportPath, $cookie)[1]['projects'], 'projectId',
            ));
            self::assertSame('3000.03', $this->request($app, 'GET', '/api/reports/monthly?month=2025-11&currency=MXN', $cookie)[1]['clientPaidTotal']);
            self::assertSame('800.00', $this->request($app, 'GET', '/api/reports/monthly?month=2025-12&currency=MXN', $cookie)[1]['clientPaidTotal']);

            $december = [...$input, 'paidOn' => '2025-12-02', 'platformFee' => '0', 'withheldTaxAmount' => '0'];
            self::assertSame(201, $this->write($app, '/api/payments', [...$december, 'clientPaidAmount' => '1200'], $cookie, key: 'settled')[0]);
            self::assertSame('settled', $this->request($app, 'GET', $detailPath, $cookie)[1]['collectionStatus']);
            self::assertSame(201, $this->write($app, '/api/payments', [...$december, 'clientPaidAmount' => '300',
                'excessReason' => 'Trabajo adicional'], $cookie, key: 'overpaid')[0]);
            $overpaid = $this->request($app, 'GET', $reportPath, $cookie)[1]['projects'][0];
            self::assertSame('overpaid', $overpaid['collectionStatus']);
            self::assertSame('300.00', $overpaid['excessAmount']);
            self::assertSame('0.00', $overpaid['outstandingBalance']);
        } finally {
            if ($writer->inTransaction()) {
                $writer->rollBack();
            }
            foreach (['payment_charges', 'payments', 'projects', 'clients', 'server_sessions'] as $table) {
                $database->prepare("DELETE FROM {$table} WHERE user_id IN (?, ?)")->execute([$ana, $luis]);
            }
            $database->prepare('DELETE FROM users WHERE id IN (?, ?)')->execute([$ana, $luis]);
        }
    }

    public function testCorrectsAndDeletesAnOwnedPaymentWithoutRecreatingIt(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated PostgreSQL test database');
        }
        $root = dirname(__DIR__, 2);
        $container = require $root . '/config/container.php';
        $app = $container->get(Application::class);
        (require $root . '/config/pipeline.php')($app, $container->get(MiddlewareFactory::class));
        (require $root . '/config/routes.php')($app);
        $database = (require $root . '/config/database.php')->connect();
        $suffix = bin2hex(random_bytes(8));
        [$ana, $cookie] = $this->register($app, 'Ana', "correction-{$suffix}@example.com", 'Una contraseña exacta');
        [$luis, $other] = $this->register($app, 'Luis', "other-correction-{$suffix}@example.com", 'Una contraseña exacta');

        try {
            $client = (new CreateClient($database))->create($ana, 'Cliente');
            $project = (new CreateProject($database))->create($ana, $client, 'Proyecto', ['5000.00', 'MXN', 3, 'Directo']);
            $edit = ['paidOn' => '2025-11-30', 'sourceType' => 'direct_client', 'clientPaidAmount' => '3000',
                'platformFee' => '150', 'withheldTaxAmount' => '50'];
            $capture = [...$edit, 'projectId' => $project, 'currency' => 'MXN'];
            [$status, $payment] = $this->write($app, '/api/payments', $capture, $cookie, key: 'capture');
            self::assertSame(201, $status);
            $path = "/api/payments/{$payment['id']}";
            $charge = ['paidOn' => '2025-11-30', 'chargeType' => 'tax', 'amount' => '20', 'currency' => 'MXN'];
            self::assertSame(201, $this->write($app, "$path/charges", $charge, $cookie, key: 'charge')[0]);
            $detail = $this->request($app, 'GET', $path, $cookie)[1];
            self::assertSame('2', $detail['version']);
            self::assertSame(412, $this->write($app, $path, [], $cookie, 'DELETE', version: '"1"')[0]);
            $edit = [...$edit, 'paidOn' => '2025-12-01', 'clientPaidAmount' => '2500'];
            self::assertSame(404, $this->write($app, $path, $edit, $other, 'PATCH', 'edit', '"2"')[0]);
            self::assertSame(403, $this->request($app, 'PATCH', $path, $cookie, $edit, version: '"2"')[0]);
            self::assertSame(422, $this->write($app, $path, [...$edit, 'paidOn' => '2999-01-01'], $cookie, 'PATCH', 'edit', '"2"')[0]);
            self::assertSame(422, $this->write($app, $path, [...$edit, 'clientPaidAmount' => '6000'], $cookie, 'PATCH', 'edit', '"2"')[0]);
            [$status, $corrected] = $this->write($app, $path, $edit, $cookie, 'PATCH', 'edit', '"2"');
            self::assertSame(200, $status, json_encode($corrected));
            self::assertSame('3', $corrected['version']);
            self::assertSame($detail['charges'], $corrected['charges']);
            self::assertSame(200, $this->write($app, $path, $edit, $cookie, 'PATCH', 'edit', '"2"')[0]);
            self::assertSame(409, $this->write($app, $path, [...$edit, 'clientPaidAmount' => '2501'], $cookie, 'PATCH', 'edit', '"2"')[0]);
            $audit = $database->prepare('SELECT action, reason, previous_values FROM payment_revisions WHERE payment_id = ? ORDER BY result_version');
            $audit->execute([$payment['id']]);
            $rows = $audit->fetchAll(PDO::FETCH_ASSOC);
            self::assertCount(1, $rows);
            self::assertSame('3000.000000', json_decode($rows[0]['previous_values'], true)['client_paid_amount']);
            $month = '/api/reports/monthly?currency=MXN&month=';
            self::assertSame('0.00', $this->request($app, 'GET', $month . '2025-11', $cookie)[1]['clientPaidTotal']);
            self::assertSame('2500.00', $this->request($app, 'GET', $month . '2025-12', $cookie)[1]['clientPaidTotal']);
            $projectPath = "/api/projects/{$project}";
            self::assertSame('2500.00', $this->request($app, 'GET', $projectPath, $cookie)[1]['outstandingBalance']);
            self::assertSame(200, $this->write($app, $path, ['clientPaidAmount' => '2400'], $cookie, 'PATCH')[0]);
            self::assertSame(412, $this->write($app, $path, $edit, $cookie, 'PATCH', 'edit', '"2"')[0]);

            self::assertSame(404, $this->write($app, $path, [], $other, 'DELETE', version: '"4"')[0]);
            self::assertSame(403, $this->request($app, 'DELETE', $path, $cookie, [], version: '"4"')[0]);
            self::assertSame(428, $this->write($app, $path, [], $cookie, 'DELETE')[0]);
            self::assertSame(412, $this->write($app, $path, [], $cookie, 'DELETE', version: '"3"')[0]);
            $writer = (require $root . '/config/database.php')->connect();
            $database->beginTransaction();
            $repo = new \FlowBalance\Finance\Infrastructure\PostgresPaymentCorrectionRepository($database);
            $repo->lock($ana, $payment['id']);
            $writer->exec("SET lock_timeout = '100ms'");
            $otherRepository = new \FlowBalance\Finance\Infrastructure\PostgresPaymentCorrectionRepository($writer);
            foreach ([
                static fn () => (new \FlowBalance\Finance\Application\RecordPaymentCharge($writer))->record(
                    $ana, $payment['id'], 'concurrent-charge', 'tax', '2025-11-30', '20.00',
                ),
                static fn () => (new \FlowBalance\Finance\Application\EditPayment($otherRepository))->edit(
                    $ana, $payment['id'], $edit, '4', 'concurrent-edit',
                ),
            ] as $operation) {
                $writer->beginTransaction();
                try {
                    $operation();
                    self::fail('Expected the operation to wait for the payment lock');
                } catch (PDOException $error) {
                    self::assertSame('55P03', $error->getCode());
                } finally {
                    $writer->rollBack();
                }
            }
            $database->rollBack();
            self::assertSame(204, $this->write($app, $path, ['reason' => ' Captura duplicada '], $cookie, 'DELETE', version: '"4"')[0]);
            self::assertSame(204, $this->write($app, $path, ['reason' => 'Otro motivo'], $cookie, 'DELETE', version: '"4"')[0]);
            self::assertSame(404, $this->request($app, 'GET', $path, $cookie)[0]);
            self::assertSame(404, $this->write($app, "$path/charges", $charge, $cookie, key: 'new-charge')[0]);
            [$status, $error] = $this->write($app, '/api/payments', $capture, $cookie, key: 'capture');
            self::assertSame(409, $status);
            self::assertSame('payment_deleted', $error['error']['code']);
            self::assertSame('0.00', $this->request($app, 'GET', $month . '2025-12', $cookie)[1]['clientPaidTotal']);
            self::assertSame('5000.00', $this->request($app, 'GET', $projectPath, $cookie)[1]['outstandingBalance']);
            self::assertSame([], $this->request($app, 'GET', '/api/dashboard', $cookie)[1]['payments']);
            self::assertSame('5000.00', $this->request($app, 'GET', '/api/reports/project-balances', $cookie)[1]['projects'][0]['outstandingBalance']);
            $audit->execute([$payment['id']]);
            $rows = $audit->fetchAll(PDO::FETCH_ASSOC);
            self::assertSame(['edit', 'edit', 'delete'], array_column($rows, 'action'));
            self::assertSame('Captura duplicada', $rows[2]['reason']);
            self::assertSame(1, (int) $database->query('SELECT COUNT(*) FROM payment_charges WHERE payment_id = ' . (int) $payment['id'])->fetchColumn());
            $newId = (new RecordPayment($database))->record($ana, $project, '10', 'MXN', '2025-12-01', 'second-payment');
            self::assertSame(204, $this->write($app, "/api/payments/{$newId}", [], $cookie, 'DELETE', version: '"1"')[0]);
        } finally {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            foreach (['payment_revisions', 'payment_charges', 'payments', 'projects', 'clients', 'server_sessions'] as $table) {
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

    private function write(Application $app, string $path, array $data, string $cookie = '', string $method = 'POST', string $key = '', string $version = ''): array
    {
        [$status, $challenge, $cookie] = $this->request($app, 'GET', '/api/auth/csrf', $cookie);
        self::assertSame(200, $status, json_encode($challenge));
        return $this->request($app, $method, $path, $cookie, $data, $challenge['csrfToken'], $key, $version);
    }

    private function request(Application $app, string $method, string $path, string $cookie, ?array $data = null, string $csrf = '', string $key = '', string $version = ''): array
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
        if ($version !== '') {
            $request = $request->withHeader('If-Match', $version);
        }
        if ($key !== '') {
            $request = $request->withHeader('Idempotency-Key', $key);
        }
        $response = $app->handle($request);
        if (preg_match('/^__Host-flow_balance=([a-f0-9]{64});/', $response->getHeaderLine('Set-Cookie'), $match)) {
            $cookie = $match[1];
        }
        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true), $cookie, $response->getHeaderLine('Cache-Control')];
    }
}
