<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/AppClient.php';

use FlowBalance\Finance\Application\CreateClient;
use FlowBalance\Finance\Application\CreateProject;
use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\PaymentConversion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rounding contract of the MXN conversion: each figure is rounded to two decimals with halves
 * moving away from zero, and the deductions are rounded once, as a sum. Expected values are worked
 * out by hand, not taken from the database, so they hold on any engine.
 */
final class PaymentRoundingTest extends TestCase
{
    private ?PDO $database = null;
    private string $userId = '';

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $this->database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
    }

    protected function tearDown(): void
    {
        if ($this->userId !== '') {
            foreach (['payments', 'projects', 'clients', 'server_sessions'] as $table) {
                $this->database->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$this->userId]);
            }
            $this->database->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
    }

    /** @param array{0: string, 1: string, 2: string} $amounts payment, fee, tax */
    #[DataProvider('conversions')]
    public function testConvertsAndRoundsEveryFigureToTwoDecimals(array $amounts, string $currency, ?string $rate, array $expected): void
    {
        $normalizedRate = PaymentConversion::rate($currency, $rate);
        $equivalents = (new PaymentConversion($this->database))->equivalents(new PaymentAmounts(...$amounts), $currency, $normalizedRate);

        self::assertSame($expected, [
            $equivalents['clientPaidAmount'], $equivalents['platformFee'],
            $equivalents['withheldTaxAmount'], $equivalents['receivedAmount'],
        ]);
    }

    public static function conversions(): iterable
    {
        // [payment, fee, tax], currency, rate => [paid, fee, withheld tax, received], all in MXN
        yield 'half a cent rounds up' => [['0.01', '0', '0'], 'USD', '0.5', ['0.01', '0.00', '0.00', '0.01']];
        yield '0.015 rounds up' => [['0.03', '0.01', '0.02'], 'USD', '0.5', ['0.02', '0.01', '0.01', '0.00']];
        yield '100.005 rounds up' => [['5', '0', '0'], 'USD', '20.001', ['100.01', '0.00', '0.00', '100.01']];
        yield 'six decimal rate' => [['100', '5', '3'], 'USD', '17.123456', ['1712.35', '85.62', '51.37', '1575.36']];
        yield 'rounds down below the half' => [['0.01', '0', '0'], 'USD', '17.123456', ['0.17', '0.00', '0.00', '0.17']];
        yield 'fee equal to the payment' => [['0.01', '0.01', '0'], 'USD', '0.5', ['0.01', '0.01', '0.00', '0.00']];
        yield 'deductions are rounded once as a sum' => [['1', '0.01', '0.01'], 'USD', '0.5', ['0.50', '0.01', '0.00', '0.49']];
        yield 'largest amount at rate one' => [['99999999999999.99', '0', '0'], 'USD', '1', ['99999999999999.99', '0.00', '0.00', '99999999999999.99']];
        yield 'smallest amount at the largest rate' => [['0.01', '0', '0'], 'USD', '99999999999999.999999', ['1000000000000.00', '0.00', '0.00', '1000000000000.00']];
        yield 'largest amount at the smallest rate' => [['99999999999999.99', '0', '0'], 'USD', '0.000001', ['100000000.00', '0.00', '0.00', '100000000.00']];
        yield 'converts to nothing' => [['1', '0', '0'], 'USD', '0.000001', ['0.00', '0.00', '0.00', '0.00']];
        yield 'pesos are only formatted' => [['123.45', '3.21', '0.12'], 'MXN', null, ['123.45', '3.21', '0.12', '120.12']];
    }

    public function testMonthlyTotalsAddTheRoundedFiguresOfEachPayment(): void
    {
        $api = AppClient::boot();
        $registered = $api->register('Ana', 'rounding-' . bin2hex(random_bytes(6)) . '@example.test', 'Una contraseña exacta');
        self::assertSame(201, $registered[0], json_encode($registered[1]));
        $this->userId = $registered[1]['user']['id'];
        $cookie = $registered[2];
        $client = (new CreateClient($this->database))->create($this->userId, 'Cliente');
        $project = (new CreateProject($this->database))->create($this->userId, $client, 'Proyecto', ['5000.00', 'MXN', 3, 'Directo']);

        $payment = ['projectId' => $project, 'paidOn' => '2025-12-01', 'currency' => 'USD', 'exchangeRate' => '0.5',
            'sourceType' => 'direct_client', 'clientPaidAmount' => '0.01', 'platformFee' => '0', 'withheldTaxAmount' => '0'];
        foreach (['first', 'second'] as $key) {
            self::assertSame(201, $api->write('/api/payments', $payment, $cookie, key: $key)[0]);
        }

        [, $report] = $api->request('GET', '/api/reports/monthly?month=2025-12&currency=MXN', $cookie);
        // Each payment is worth 0.005 and becomes 0.01; adding first and rounding last would give 0.01.
        self::assertSame('0.02', $report['clientPaidTotal']);
        self::assertSame('0.02', $report['netReceivedTotal']);
    }
}
