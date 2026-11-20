<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/AppClient.php';
require_once __DIR__ . '/../Support/GoldenRecorder.php';

use PHPUnit\Framework\TestCase;

/**
 * Pins the observable API contract before the storage engine changes. The references in
 * golden/ were recorded on PostgreSQL; any later engine must reproduce them exactly.
 * Re-record only on a known-good engine: UPDATE_GOLDEN=1 vendor/bin/phpunit --testsuite Characterization
 */
final class ApiCharacterizationTest extends TestCase
{
    public function testApiResponsesMatchTheRecordedReference(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $api = AppClient::boot();
        $database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
        $suffix = bin2hex(random_bytes(6));
        $recorder = new GoldenRecorder($suffix);
        $password = ' Una contraseña exacta ';
        $users = [];

        try {
            $recorder->section('auth');
            $anaEmail = "ana-{$suffix}@example.com";
            $ana = $api->register('Ana', $anaEmail, $password);
            $recorder->add('register ana', $ana);
            $users[] = $ana[1]['user']['id'];
            $recorder->add('register duplicate email in capitals', $api->register('Ana', strtoupper($anaEmail), $password));
            $recorder->add('register with mismatching confirmation', $api->write('/api/auth/register', [
                'name' => 'Eva', 'email' => "eva-{$suffix}@example.com", 'password' => $password, 'passwordConfirmation' => 'otra',
            ]));
            $luis = $api->register('Luis', "luis-{$suffix}@example.com", $password);
            $recorder->add('register luis', $luis);
            $users[] = $luis[1]['user']['id'];
            $other = $luis[2];

            $recorder->add('session without cookie', $api->request('GET', '/api/auth/session', ''));
            $login = $api->write('/api/auth/login', ['email' => strtoupper($anaEmail), 'password' => $password]);
            $recorder->add('login with email in capitals', $login);
            $cookie = $login[2];
            $recorder->add('login with a wrong password', $api->write('/api/auth/login', ['email' => $anaEmail, 'password' => 'incorrecta']));
            $recorder->add('session after login', $api->request('GET', '/api/auth/session', $cookie));

            $recorder->section('clients-projects');
            $client = $api->write('/api/clients', ['name' => ' Estudio Norte '], $cookie);
            $recorder->add('create client', $client);
            $clientPath = '/api/clients/' . $client[1]['id'];
            $recorder->add('create client without a name', $api->write('/api/clients', ['name' => '  '], $cookie));
            $recorder->add('create client unauthenticated', $api->write('/api/clients', ['name' => 'Nadie']));
            $recorder->add('get client', $api->request('GET', $clientPath, $cookie));
            $recorder->add('get client of another account', $api->request('GET', $clientPath, $other));
            $recorder->add('rename client', $api->write($clientPath, ['name' => 'Estudio Norte S.A.'], $cookie, 'PATCH'));
            $recorder->add('rename client with an empty name', $api->write($clientPath, ['name' => ''], $cookie, 'PATCH'));

            $agreement = ['clientId' => $client[1]['id'], 'name' => 'Identidad visual', 'agreedAmount' => '5000',
                'agreedCurrency' => 'MXN', 'agreedPaymentCount' => 3, 'acquisitionSource' => 'Recomendación'];
            $project = $api->write('/api/projects', $agreement, $cookie);
            $recorder->add('create project', $project);
            $projectPath = '/api/projects/' . $project[1]['id'];
            $recorder->add('create project in another account', $api->write('/api/projects', $agreement, $other));
            $recorder->add('create project with an invalid payment count', $api->write('/api/projects', [...$agreement, 'agreedPaymentCount' => 0], $cookie));
            $recorder->add('get project', $api->request('GET', $projectPath, $cookie));
            $recorder->add('rename project', $api->write($projectPath, ['name' => 'Identidad visual 2026'], $cookie, 'PATCH'));
            $recorder->add('mark project as delivered', $api->write($projectPath, ['deliveryStatus' => 'delivered'], $cookie, 'PATCH'));
            $recorder->add('set an invalid delivery status', $api->write($projectPath, ['deliveryStatus' => 'invalid'], $cookie, 'PATCH'));
            $second = $api->write('/api/projects', ['clientId' => $client[1]['id'], 'name' => 'Sitio web',
                'agreedAmount' => '5000', 'agreedCurrency' => 'MXN', 'agreedPaymentCount' => 2, 'acquisitionSource' => 'Upwork'], $cookie);
            $recorder->add('create second project', $second);
            $secondId = $second[1]['id'];

            $recorder->section('payments');
            $mxn = ['projectId' => $project[1]['id'], 'paidOn' => '2025-11-30', 'currency' => 'MXN',
                'sourceType' => 'direct_client', 'clientPaidAmount' => '4800', 'platformFee' => '150.25', 'withheldTaxAmount' => '49.75'];
            $recorder->add('preview a payment in MXN', $api->write('/api/payments/preview', $mxn, $cookie));
            $recorder->add('payment with an impossible date', $api->write('/api/payments', [...$mxn, 'paidOn' => '2025-02-30'], $cookie, key: 'first'));
            $first = $api->write('/api/payments', $mxn, $cookie, key: 'first');
            $recorder->add('record the first payment', $first);
            $recorder->add('replay the first payment', $api->write('/api/payments', $mxn, $cookie, key: 'first'));
            $recorder->add('reuse a key with another date', $api->write('/api/payments', [...$mxn, 'paidOn' => '2025-12-01'], $cookie, key: 'first'));
            $excess = [...$mxn, 'clientPaidAmount' => '500', 'platformFee' => '0', 'withheldTaxAmount' => '0',
                'paidOn' => '2025-12-01', 'sourceType' => 'platform', 'sourceName' => 'Upwork'];
            $recorder->add('payment above the agreed amount without a reason', $api->write('/api/payments', $excess, $cookie, key: 'second'));
            $recorder->add('payment with an excess reason', $api->write('/api/payments', [...$excess, 'clientPaidAmount' => '300', 'excessReason' => 'Pago adicional'], $cookie, key: 'third'));
            $recorder->add('retry the second payment with a reason', $api->write('/api/payments', [...$excess, 'excessReason' => 'Trabajo adicional'], $cookie, key: 'second'));

            $usd = ['currency' => 'USD', 'exchangeRate' => '17.250000', 'clientPaidAmount' => '100',
                'platformFee' => '5', 'withheldTaxAmount' => '3', 'paidOn' => '2025-11-30', 'sourceType' => 'direct_client'];
            $recorder->add('preview a payment in USD', $api->write('/api/payments/preview', [...$usd, 'projectId' => $secondId], $cookie));
            $recorder->add('preview a USD payment that rounds', $api->write('/api/payments/preview', [...$usd, 'projectId' => $secondId,
                'exchangeRate' => '0.5', 'clientPaidAmount' => '0.03', 'platformFee' => '0.01', 'withheldTaxAmount' => '0.02'], $cookie));
            $recorder->add('preview with a malformed exchange rate', $api->write('/api/payments/preview', [...$usd, 'projectId' => $secondId, 'exchangeRate' => '17.1234567'], $cookie));
            $usdPayment = $api->write('/api/payments', [...$usd, 'projectId' => $secondId], $cookie, key: 'usd-1');
            $recorder->add('record a payment in USD', $usdPayment);
            $recorder->add('replay the USD payment', $api->write('/api/payments', [...$usd, 'projectId' => $secondId], $cookie, key: 'usd-1'));

            $firstPath = '/api/payments/' . $first[1]['id'];
            $charge = ['chargeType' => 'commission', 'paidOn' => '2025-12-02', 'amount' => '50.00', 'currency' => 'MXN'];
            $recorder->add('add a separate commission', $api->write("{$firstPath}/charges", $charge, $cookie, key: 'charge-1'));
            $recorder->add('replay the commission', $api->write("{$firstPath}/charges", $charge, $cookie, key: 'charge-1'));
            $recorder->add('reuse the commission key with another amount', $api->write("{$firstPath}/charges", [...$charge, 'amount' => '25'], $cookie, key: 'charge-1'));
            $recorder->add('add a separate tax', $api->write("{$firstPath}/charges", [...$charge, 'chargeType' => 'tax', 'paidOn' => '2025-12-03'], $cookie, key: 'charge-2'));
            $recorder->add('add a charge with three decimals', $api->write("{$firstPath}/charges", [...$charge, 'amount' => '1.001'], $cookie, key: 'charge-3'));
            $recorder->add('get the payment with its charges', $api->request('GET', $firstPath, $cookie));
            $recorder->add('get the payment of another account', $api->request('GET', $firstPath, $other));

            $usdPath = '/api/payments/' . $usdPayment[1]['id'];
            $version = '"' . $api->request('GET', $usdPath, $cookie)[1]['version'] . '"';
            $moved = [...$usd, 'paidOn' => '2025-12-01'];
            unset($moved['exchangeRate']);
            $recorder->add('preview a correction', $api->write("{$usdPath}/preview",
                ['clientPaidAmount' => '120', 'platformFee' => '5', 'withheldTaxAmount' => '3'], $cookie, version: $version));
            $recorder->add('correct the USD payment keeping its rate', $api->write($usdPath, $moved, $cookie, 'PATCH', 'keep-rate', $version));
            $recorder->add('replay the correction', $api->write($usdPath, $moved, $cookie, 'PATCH', 'keep-rate', $version));
            $recorder->add('correct with a stale version', $api->write($usdPath, $moved, $cookie, 'PATCH', 'stale', $version));
            $recorder->add('change the amount of the first payment above the agreement', $api->write($firstPath, ['clientPaidAmount' => '4900'], $cookie, 'PATCH'));

            $removable = $api->write('/api/payments', [...$mxn, 'projectId' => $secondId, 'clientPaidAmount' => '200',
                'platformFee' => '0', 'withheldTaxAmount' => '0', 'paidOn' => '2025-12-05'], $cookie, key: 'removable');
            $recorder->add('record a payment to delete', $removable);
            $removablePath = '/api/payments/' . $removable[1]['id'];
            $removableVersion = '"' . $api->request('GET', $removablePath, $cookie)[1]['version'] . '"';
            $recorder->add('delete with a stale version', $api->write($removablePath, [], $cookie, 'DELETE', version: '"99"'));
            $recorder->add('delete the payment', $api->write($removablePath, [], $cookie, 'DELETE', version: $removableVersion));
            $recorder->add('get the deleted payment', $api->request('GET', $removablePath, $cookie));

            $recorder->section('reports');
            $recorder->add('payment list', $api->request('GET', '/api/payments', $cookie));
            $page = $api->request('GET', '/api/payments?limit=2', $cookie);
            $recorder->add('payment list, first page of two', $page);
            if (is_string($page[1]['nextCursor'] ?? null)) {
                $recorder->add('payment list, second page', $api->request('GET', '/api/payments?limit=2&cursor=' . rawurlencode($page[1]['nextCursor']), $cookie));
            }
            $recorder->add('payment list filtered by month', $api->request('GET', '/api/payments?month=2025-12', $cookie));
            foreach (['2025-11', '2025-12', '2026-01'] as $month) {
                $recorder->add("monthly report {$month}", $api->request('GET', "/api/reports/monthly?month={$month}&currency=MXN", $cookie));
            }
            $recorder->add('monthly report of another account', $api->request('GET', '/api/reports/monthly?month=2025-12&currency=MXN', $other));
            $recorder->add('monthly report export', $api->request('GET', '/api/reports/monthly/export?month=2025-12&currency=MXN', $cookie));
            $recorder->add('monthly report with an invalid month', $api->request('GET', '/api/reports/monthly?month=2025-13&currency=MXN', $cookie));
            $recorder->add('project balances', $api->request('GET', '/api/reports/project-balances', $cookie));
            $recorder->add('dashboard', $api->request('GET', '/api/dashboard', $cookie));
            $recorder->add('dashboard of an empty account', $api->request('GET', '/api/dashboard', $other));

            $recorder->section('logout');
            $recorder->add('logout without a CSRF token', $api->request('POST', '/api/auth/logout', $cookie, []));
            $recorder->add('logout', $api->write('/api/auth/logout', [], $cookie));
            $recorder->add('session after logout', $api->request('GET', '/api/auth/session', $cookie));
            $recorder->add('dashboard after logout', $api->request('GET', '/api/dashboard', $cookie));
        } finally {
            if ($users) {
                $marks = implode(', ', array_fill(0, count($users), '?'));
                foreach (['payment_revisions', 'payment_charges', 'payments', 'projects', 'clients', 'server_sessions'] as $table) {
                    $database->prepare("DELETE FROM {$table} WHERE user_id IN ({$marks})")->execute($users);
                }
                $database->prepare("DELETE FROM users WHERE id IN ({$marks})")->execute($users);
            }
        }

        $recorder->assertMatches(__DIR__ . '/golden', getenv('UPDATE_GOLDEN') === '1');
    }
}
