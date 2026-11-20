<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the schema itself refuses, whatever the application does. Every rejection is paired with an
 * accepted control row that differs only in the field under test, so a test cannot pass because of an
 * unrelated error such as a missing column. Runs in a transaction that is always rolled back.
 */
final class SchemaConstraintsTest extends TestCase
{
    private PDO $database;
    private int $sequence = 0;
    private string $user;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_PASSWORD') === false) {
            self::markTestSkipped('Configure the dedicated test database');
        }
        $this->database = (require dirname(__DIR__, 2) . '/config/database.php')->connect();
        $this->database->beginTransaction();
        $this->user = $this->insert('users', $this->userRow());
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->inTransaction()) {
            $this->database->rollBack();
        }
    }

    private function userRow(string $email = null): array
    {
        $email ??= 'user-' . ++$this->sequence . '-' . bin2hex(random_bytes(4)) . '@example.test';
        return ['name' => 'Ana', 'email' => $email, 'email_normalized' => $email, 'password_hash' => 'x'];
    }

    private function insert(string $table, array $row): string
    {
        $columns = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        $this->database->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$marks})")->execute(array_values($row));
        return (string) $this->database->lastInsertId();
    }

    /** The row is refused by the database (a constraint, a range or a key), and nothing was stored. */
    private function assertRefused(string $table, array $row, string $why): void
    {
        try {
            $this->insert($table, $row);
        } catch (PDOException) {
            self::addToAssertionCount(1);
            return;
        }
        self::fail("{$table} accepted a row it must refuse: {$why}");
    }

    private function client(): string
    {
        return $this->insert('clients', ['user_id' => $this->user, 'name' => 'Cliente']);
    }

    private function project(array $extra = []): string
    {
        return $this->insert('projects', ['user_id' => $this->user, 'client_id' => $this->client(), 'name' => 'Proyecto', ...$extra]);
    }

    private function paymentRow(string $project, array $extra = []): array
    {
        return ['user_id' => $this->user, 'project_id' => $project, 'client_paid_amount' => '1000.00', 'currency' => 'MXN',
            'paid_on' => '2025-11-30', 'idempotency_key' => 'key-' . ++$this->sequence, 'request_fingerprint' => str_repeat('a', 64), ...$extra];
    }

    public function testNamesAndEmailsAreCheckedAndEmailsAreComparedExactly(): void
    {
        $this->insert('users', [...$this->userRow(), 'name' => 'Luis']);
        $this->assertRefused('users', [...$this->userRow(), 'name' => '   '], 'a blank name');
        $this->assertRefused('users', [...$this->userRow(), 'email' => '  '], 'a blank email');

        $taken = 'taken-' . bin2hex(random_bytes(4)) . '@example.test';
        $this->insert('users', $this->userRow($taken));
        $this->assertRefused('users', $this->userRow($taken), 'the same normalized email twice');
        // Accents are not case: "nandu" and "ñandú" are different addresses.
        $this->insert('users', $this->userRow('nandu-' . $this->sequence . '@example.test'));
        $this->insert('users', $this->userRow('ñandú-' . $this->sequence . '@example.test'));
    }

    public function testNamesCannotCarryLeadingOrTrailingSpaces(): void
    {
        $this->insert('clients', ['user_id' => $this->user, 'name' => 'Ana']);
        foreach ([' Ana', 'Ana ', '', '  '] as $name) {
            $this->assertRefused('clients', ['user_id' => $this->user, 'name' => $name], "client name '{$name}'");
        }
        $client = $this->client();
        $this->insert('projects', ['user_id' => $this->user, 'client_id' => $client, 'name' => 'Sitio']);
        $this->assertRefused('projects', ['user_id' => $this->user, 'client_id' => $client, 'name' => 'Sitio '], 'a trailing space in a project name');
    }

    public function testIdempotencyKeysAreExactNotCaseOrSpaceInsensitive(): void
    {
        $project = $this->project();
        foreach (['ABC', 'abc', 'k', 'k '] as $key) {
            $this->insert('payments', $this->paymentRow($project, ['idempotency_key' => $key]));
        }
        $this->assertRefused('payments', $this->paymentRow($project, ['idempotency_key' => 'ABC']), 'an exact duplicate key');
        $this->assertRefused('payments', $this->paymentRow($project, ['idempotency_key' => 'k ']), 'an exact duplicate key with a space');
    }

    public function testKeysAreScopedPerAccount(): void
    {
        $project = $this->project();
        $this->insert('payments', $this->paymentRow($project, ['idempotency_key' => 'shared']));
        $firstAccount = $this->user;
        $this->user = $this->insert('users', $this->userRow());
        $this->insert('payments', $this->paymentRow($this->project(), ['idempotency_key' => 'shared']));

        $count = $this->database->query("SELECT COUNT(DISTINCT user_id) FROM payments WHERE idempotency_key = 'shared'");
        self::assertSame(2, (int) $count->fetchColumn());
        self::assertNotSame($firstAccount, $this->user);
    }

    public function testMoneyWithMoreThanTwoDecimalsOrOutOfRangeIsRefused(): void
    {
        $project = $this->project();
        $this->insert('payments', $this->paymentRow($project, ['platform_fee' => '150.25', 'withheld_tax_amount' => '49.75']));
        $cases = [
            'a fee with three decimals' => ['platform_fee' => '1.005'],
            'a withheld tax with three decimals' => ['withheld_tax_amount' => '0.001'],
            'a negative fee' => ['platform_fee' => '-1.00'],
            'a negative withheld tax' => ['withheld_tax_amount' => '-0.01'],
            'a zero payment' => ['client_paid_amount' => '0'],
            'a negative payment' => ['client_paid_amount' => '-5'],
            'deductions above the payment' => ['platform_fee' => '900.00', 'withheld_tax_amount' => '100.01'],
            'fifteen integer digits' => ['client_paid_amount' => '100000000000000.00'],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('payments', $this->paymentRow($project, $override), $why);
        }
    }

    public function testProjectAgreementRules(): void
    {
        $this->project(['agreed_amount' => '5000.00', 'agreed_currency' => 'MXN', 'agreed_payment_count' => 3, 'acquisition_source' => 'Recomendación']);
        $client = $this->client();
        $base = ['user_id' => $this->user, 'client_id' => $client, 'name' => 'Otro'];
        $this->insert('projects', [...$base, 'agreed_amount' => '99999999999999.99', 'agreed_currency' => 'MXN']);
        $cases = [
            'an amount with three decimals' => ['agreed_amount' => '5000.001', 'agreed_currency' => 'MXN'],
            'an amount without a currency' => ['agreed_amount' => '5000.00'],
            'a currency without an amount' => ['agreed_currency' => 'MXN'],
            'an amount in dollars' => ['agreed_amount' => '5000.00', 'agreed_currency' => 'USD'],
            'a currency in lower case' => ['agreed_amount' => '5000.00', 'agreed_currency' => 'mxn'],
            'a zero amount' => ['agreed_amount' => '0', 'agreed_currency' => 'MXN'],
            'fifteen integer digits' => ['agreed_amount' => '100000000000000', 'agreed_currency' => 'MXN'],
            'a zero payment count' => ['agreed_payment_count' => 0],
            'a source with a trailing space' => ['acquisition_source' => 'Directo '],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('projects', [...$base, ...$override], $why);
        }
    }

    public function testExchangeRateAllowsSixDecimalsAndRefusesMore(): void
    {
        $project = $this->project();
        $usd = ['currency' => 'USD', 'client_paid_amount' => '100.00'];
        foreach (['17.123456', '17.25', '0.000001', '99999999999999.999999'] as $rate) {
            $this->insert('payments', $this->paymentRow($project, [...$usd, 'exchange_rate' => $rate]));
        }
        $this->insert('payments', $this->paymentRow($project, ['currency' => 'MXN']));
        $cases = [
            'seven decimals' => [...$usd, 'exchange_rate' => '1.0000001'],
            'twelve decimals' => [...$usd, 'exchange_rate' => '1.000000000001'],
            'a zero rate' => [...$usd, 'exchange_rate' => '0'],
            'a negative rate' => [...$usd, 'exchange_rate' => '-1'],
            'fifteen integer digits' => [...$usd, 'exchange_rate' => '100000000000000'],
            'a rate on pesos' => ['currency' => 'MXN', 'exchange_rate' => '17.25'],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('payments', $this->paymentRow($project, $override), $why);
        }
    }

    public function testPaymentSourceRules(): void
    {
        $project = $this->project();
        $this->insert('payments', $this->paymentRow($project));
        $this->insert('payments', $this->paymentRow($project, ['source_type' => 'direct_client']));
        $this->insert('payments', $this->paymentRow($project, ['source_type' => 'platform', 'source_name' => 'Upwork']));
        $this->insert('payments', $this->paymentRow($project, ['source_type' => 'other', 'source_name' => 'Referido']));
        $cases = [
            'a direct client with a name' => ['source_type' => 'direct_client', 'source_name' => 'Nadie'],
            'a platform without a name' => ['source_type' => 'platform'],
            'a name without a type' => ['source_name' => 'Upwork'],
            'an unknown type' => ['source_type' => 'marketplace', 'source_name' => 'X'],
            'a type in capitals' => ['source_type' => 'DIRECT_CLIENT'],
            'a name with a trailing space' => ['source_type' => 'platform', 'source_name' => 'Upwork '],
            'an excess reason with a trailing space' => ['excess_reason' => 'Extra '],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('payments', $this->paymentRow($project, $override), $why);
        }
    }

    public function testChargeRules(): void
    {
        $payment = $this->insert('payments', $this->paymentRow($this->project()));
        $charge = fn (array $extra = []): array => ['user_id' => $this->user, 'payment_id' => $payment, 'charge_type' => 'commission',
            'paid_on' => '2025-12-02', 'amount' => '50.00', 'currency' => 'MXN', 'idempotency_key' => 'charge-' . ++$this->sequence,
            'request_fingerprint' => str_repeat('b', 64), ...$extra];
        $this->insert('payment_charges', $charge());
        $this->insert('payment_charges', $charge(['charge_type' => 'tax']));
        $cases = [
            'three decimals' => ['amount' => '1.001'],
            'a zero amount' => ['amount' => '0'],
            'a negative amount' => ['amount' => '-1'],
            'dollars' => ['currency' => 'USD'],
            'pesos in lower case' => ['currency' => 'mxn'],
            'an unknown type' => ['charge_type' => 'fee'],
            'a type in capitals' => ['charge_type' => 'TAX'],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('payment_charges', $charge($override), $why);
        }
    }

    public function testOwnershipIsEnforcedByCompositeKeys(): void
    {
        $project = $this->project();
        $this->insert('payments', $this->paymentRow($project));
        $stranger = $this->insert('users', $this->userRow());
        $this->assertRefused('payments', $this->paymentRow($project, ['user_id' => $stranger]), "a payment on another account's project");
        $this->assertRefused('projects', ['user_id' => $stranger, 'client_id' => $this->client(), 'name' => 'Ajeno'], "a project on another account's client");
        $this->assertRefused('payments', $this->paymentRow('9223372036854775807'), 'a payment for a project that does not exist');
    }

    public function testRowsInUseCannotBeDeleted(): void
    {
        $payment = $this->insert('payments', $this->paymentRow($this->project()));
        $this->insert('payment_charges', ['user_id' => $this->user, 'payment_id' => $payment, 'charge_type' => 'tax', 'paid_on' => '2025-12-02',
            'amount' => '10.00', 'currency' => 'MXN', 'idempotency_key' => 'charge-in-use', 'request_fingerprint' => str_repeat('b', 64)]);
        foreach (['payments', 'projects', 'users'] as $table) {
            $column = $table === 'users' ? 'id' : 'user_id';
            try {
                $this->database->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$this->user]);
                self::fail("Deleting {$table} must be restricted while dependants exist");
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPaymentRevisionRules(): void
    {
        $payment = $this->insert('payments', $this->paymentRow($this->project()));
        $revision = fn (array $extra = []): array => ['user_id' => $this->user, 'payment_id' => $payment, 'actor_id' => $this->user,
            'previous_version' => 1, 'result_version' => 2, 'action' => 'edit', 'previous_values' => '{"exchange_rate":"17.25"}', ...$extra];
        $this->insert('payment_revisions', $revision());
        $this->insert('payment_revisions', $revision(['previous_version' => 2, 'result_version' => 3, 'request_key' => 'k', 'fingerprint' => str_repeat('c', 64)]));
        $cases = [
            'a version that skips one' => ['previous_version' => 3, 'result_version' => 5],
            'a repeated result version' => ['previous_version' => 1, 'result_version' => 2],
            'an actor that is not the owner' => ['previous_version' => 3, 'result_version' => 4, 'actor_id' => $this->insert('users', $this->userRow())],
            'a key without a fingerprint' => ['previous_version' => 3, 'result_version' => 4, 'request_key' => 'only-key'],
            'a fingerprint without a key' => ['previous_version' => 3, 'result_version' => 4, 'fingerprint' => str_repeat('d', 64)],
            'a delete carrying a key' => ['previous_version' => 3, 'result_version' => 4, 'action' => 'delete', 'request_key' => 'z', 'fingerprint' => str_repeat('e', 64)],
            'an unknown action' => ['previous_version' => 3, 'result_version' => 4, 'action' => 'purge'],
            'values that are not JSON' => ['previous_version' => 3, 'result_version' => 4, 'previous_values' => '{not json'],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('payment_revisions', $revision($override), $why);
        }
    }

    public function testAgreementRevisionRules(): void
    {
        $project = $this->project();
        $row = fn (array $extra = []): array => ['user_id' => $this->user, 'project_id' => $project, 'previous_agreed_amount' => '5000.00',
            'agreed_amount' => '4500.00', 'client_paid_total' => '4800.00', 'excess_amount' => '300.00', 'reason' => 'Se redujo el alcance', ...$extra];
        $this->insert('project_agreement_revisions', $row());
        $cases = [
            'an agreement that does not decrease' => ['agreed_amount' => '5000.00', 'excess_amount' => '-200.00'],
            'an excess that is not paid minus agreed' => ['excess_amount' => '299.00'],
            'a zero excess' => ['client_paid_total' => '4500.00', 'excess_amount' => '0'],
            'a blank reason' => ['reason' => '   '],
        ];
        foreach ($cases as $why => $override) {
            $this->assertRefused('project_agreement_revisions', $row($override), $why);
        }
    }

    public function testDeliveryStatusDefaultsOnlyForNewRows(): void
    {
        $client = $this->client();
        $id = $this->insert('projects', ['user_id' => $this->user, 'client_id' => $client, 'name' => 'Nuevo']);
        $status = $this->database->prepare('SELECT delivery_status FROM projects WHERE id = ?');
        $status->execute([$id]);
        self::assertSame('in_progress', $status->fetchColumn());

        $this->insert('projects', ['user_id' => $this->user, 'client_id' => $client, 'name' => 'Entregado', 'delivery_status' => 'delivered']);
        foreach (['DELIVERED', 'done', ''] as $bad) {
            $this->assertRefused('projects', ['user_id' => $this->user, 'client_id' => $client, 'name' => "P {$bad}", 'delivery_status' => $bad], "status '{$bad}'");
        }
    }

    public function testSessionAndThrottleDefaults(): void
    {
        $now = '2026-11-20 10:00:00.000000';
        $session = fn (array $extra = []): array => ['token_hash' => str_repeat('a', 64), 'payload' => '{"userId":1}', 'created_at' => $now,
            'last_activity_at' => $now, 'expires_at' => $now, ...$extra];
        $this->insert('server_sessions', $session());
        $this->insert('server_sessions', $session(['token_hash' => strtoupper(str_repeat('a', 64))]));
        $this->assertRefused('server_sessions', $session(), 'the same token hash twice');
        $this->assertRefused('server_sessions', $session(['token_hash' => str_repeat('b', 64), 'payload' => 'not json']), 'a payload that is not JSON');

        $this->insert('login_throttles', ['key_hash' => str_repeat('c', 64), 'updated_at' => $now]);
        self::assertSame('[]', $this->database->query("SELECT failed_at FROM login_throttles WHERE key_hash = '" . str_repeat('c', 64) . "'")->fetchColumn());
    }

    public function testIdentifiersAreAutoIncrementedAndAcceptExplicitValues(): void
    {
        $first = (int) $this->client();
        self::assertGreaterThan(0, $first);
        self::assertSame($first + 1, (int) $this->client());
        $explicit = '9000000000000000001';
        $this->insert('clients', ['id' => $explicit, 'user_id' => $this->user, 'name' => 'Explícito']);
        self::assertSame($explicit, (string) $this->database->query("SELECT id FROM clients WHERE id = {$explicit}")->fetchColumn());
    }
}
