<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Infrastructure;

use FlowBalance\Finance\Application\DecimalText;
use FlowBalance\Finance\Application\PaymentAmounts;
use FlowBalance\Finance\Application\PaymentConversion;
use FlowBalance\Finance\Application\ProjectBalance;
use PDO;

final readonly class SqlOwnedResourceRepository
{
    public function __construct(private PDO $database) {}

    public function find(string $type, string $userId, string $id, bool $lock = false): ?array
    {
        $projection = $this->projection($type);
        $active = $type === 'payments' ? ' AND deleted_at IS NULL' : '';
        $query = $this->database->prepare(
            "SELECT {$projection} FROM {$type} WHERE user_id = ? AND id = ?{$active}" . ($lock ? ' FOR UPDATE' : '')
        );
        $query->execute([$userId, $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->present($type, $row) : null;
    }

    public function detail(string $type, string $userId, string $id): ?array
    {
        if ($type === 'projects') {
            return $this->projectDetail($userId, $id);
        }
        $resource = $this->find($type, $userId, $id);
        if ($resource === null || $type === 'clients') {
            return $resource;
        }
        $project = $this->find('projects', $userId, $resource['projectId']);
        $resource['project'] = $project;
        $resource['client'] = $this->find('clients', $userId, $project['clientId']);
        $amounts = new PaymentAmounts($resource['clientPaidAmount'], $resource['platformFee'], $resource['withheldTaxAmount'], allowZeroAmount: true);
        $query = $this->database->prepare('SELECT CAST(id AS CHAR) AS id, charge_type AS chargeType, paid_on AS paidOn,
                CAST(ROUND(amount, 2) AS CHAR) AS amount, currency
            FROM payment_charges WHERE user_id = ? AND payment_id = ? ORDER BY paid_on, id');
        $query->execute([$userId, $id]);
        $equivalents = (new PaymentConversion($this->database))->equivalents($amounts, $resource['currency'], $resource['exchangeRate']);
        return [...$resource, ...get_object_vars($amounts), 'equivalents' => $equivalents,
            'conversionPending' => $equivalents === null, 'clientPaidAmount' => $resource['sourceType'] === null
            ? $resource['clientPaidAmount'] . '0000' : $amounts->clientPaidAmount, 'charges' => $query->fetchAll(PDO::FETCH_ASSOC)];
    }

    private function projectDetail(string $userId, string $id): ?array
    {
        $query = $this->database->prepare(<<<'SQL'
            SELECT CAST(p.id AS CHAR) AS id, p.name, CAST(p.client_id AS CHAR) AS clientId,
                   CAST(ROUND(p.agreed_amount, 2) AS CHAR) AS agreedAmount,
                   p.agreed_currency AS agreedCurrency, p.agreed_payment_count AS agreedPaymentCount,
                   p.acquisition_source AS acquisitionSource, p.delivery_status AS deliveryStatus, c.name AS client_name,
                   NOT EXISTS (SELECT 1 FROM payments WHERE user_id = p.user_id AND project_id = p.id) AS canChangeClient
            FROM projects p
            JOIN clients c ON c.user_id = p.user_id AND c.id = p.client_id
            WHERE p.user_id = ? AND p.id = ?
            SQL);
        $query->execute([$userId, $id]);
        $project = $query->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            return null;
        }
        $project['canChangeClient'] = (bool) $project['canChangeClient'];

        // The payment list is built here and not with JSON functions, which would emit amounts as numbers.
        $amount = PaymentConversion::paymentAmount();
        $own = 'FROM payments WHERE user_id = ? AND project_id = ? AND deleted_at IS NULL';
        $rows = $this->database->prepare("SELECT CAST(id AS CHAR) AS id, paid_on AS paidOn, currency,
                CAST(ROUND(client_paid_amount, 2) AS CHAR) AS clientPaidAmount, CAST({$amount} AS CHAR) AS clientPaidAmountMxn,
                source_type AS sourceType, source_name AS sourceName
            {$own} ORDER BY paid_on DESC, id DESC");
        $rows->execute([$userId, $id]);
        $payments = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'paidOn' => $row['paidOn'], 'currency' => $row['currency'],
            'clientPaidAmount' => $row['clientPaidAmount'], 'clientPaidAmountMxn' => $row['clientPaidAmountMxn'],
            'conversionPending' => $row['clientPaidAmountMxn'] === null,
            'sourceType' => $row['sourceType'], 'sourceName' => $row['sourceName'],
        ], $rows->fetchAll(PDO::FETCH_ASSOC));
        $total = $this->database->prepare("SELECT CAST(COALESCE(SUM({$amount}), 0.00) AS CHAR) {$own}");
        $total->execute([$userId, $id]);

        $project = [...$project, 'paymentCount' => count($payments),
            'unconvertedPaymentCount' => count(array_filter($payments, static fn (array $payment): bool => $payment['conversionPending'])),
            'clientPaidTotal' => $total->fetchColumn(), 'payments' => $payments];
        $project['client'] = ['id' => $project['clientId'], 'name' => $project['client_name']];
        unset($project['client_name']);
        $project['isBalanceProvisional'] = $project['unconvertedPaymentCount'] > 0 && $project['agreedAmount'] !== null;
        return [...$project, ...ProjectBalance::calculate($project['agreedAmount'], $project['clientPaidTotal'])];
    }

    public function hasPayments(string $userId, string $projectId): bool
    {
        $query = $this->database->prepare('SELECT 1 FROM payments WHERE user_id = ? AND project_id = ? LIMIT 1');
        $query->execute([$userId, $projectId]);
        return (bool) $query->fetchColumn();
    }

    public function update(string $type, string $userId, string $id, array $changes): array
    {
        $projection = $this->projection($type);
        $assignments = [];
        foreach (array_keys($changes) as $field) {
            $assignments[] = match ($field) {
                'name' => 'name = ?',
                'clientId' => 'client_id = ?',
                'clientPaidAmount' => 'client_paid_amount = ?',
                'agreedAmount' => "agreed_amount = ?, agreed_currency = 'MXN'",
                'agreedPaymentCount' => 'agreed_payment_count = ?',
                'acquisitionSource' => 'acquisition_source = ?',
                'deliveryStatus' => 'delivery_status = ?',
            };
        }
        $set = implode(', ', $assignments);
        // MariaDB cannot return the row an UPDATE touched: the caller already holds its lock, so a read sees this write.
        $this->database->prepare("UPDATE {$type} SET {$set}, updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ? AND id = ?")
            ->execute([...array_values($changes), $userId, $id]);
        $query = $this->database->prepare("SELECT {$projection} FROM {$type} WHERE user_id = ? AND id = ?");
        $query->execute([$userId, $id]);
        return $this->present($type, $query->fetch(PDO::FETCH_ASSOC));
    }

    public function recordAgreementRevision(string $userId, string $id, string $previous, string $amount, array $preview, string $reason): void
    {
        $revision = $this->database->prepare('INSERT INTO project_agreement_revisions
            (user_id, project_id, previous_agreed_amount, agreed_amount, client_paid_total, excess_amount, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $revision->execute([$userId, $id, $previous, $amount, $preview['clientPaidTotal'], $preview['excessAmount'], $reason]);
    }

    /** Formats what the columns cannot: the exchange rate is shown without the zeros its wider column adds. */
    private function present(string $type, array|false $row): array
    {
        if ($type === 'payments' && is_array($row)) {
            $row['exchangeRate'] = DecimalText::trimmed($row['exchangeRate']);
        }
        return $row;
    }

    private function projection(string $type): string
    {
        return match ($type) {
            'clients' => 'CAST(id AS CHAR) AS id, name',
            'projects' => 'CAST(id AS CHAR) AS id, name, CAST(client_id AS CHAR) AS clientId, CAST(ROUND(agreed_amount, 2) AS CHAR) AS agreedAmount, agreed_currency AS agreedCurrency, agreed_payment_count AS agreedPaymentCount, acquisition_source AS acquisitionSource, delivery_status AS deliveryStatus',
            'payments' => 'CAST(id AS CHAR) AS id, CAST(version AS CHAR) AS version, CAST(project_id AS CHAR) AS projectId, CAST(ROUND(client_paid_amount, 2) AS CHAR) AS clientPaidAmount, currency, CAST(exchange_rate AS CHAR) AS exchangeRate, paid_on AS paidOn, CAST(ROUND(platform_fee, 2) AS CHAR) AS platformFee, CAST(ROUND(withheld_tax_amount, 2) AS CHAR) AS withheldTaxAmount, source_type AS sourceType, source_name AS sourceName, excess_reason AS excessReason',
        };
    }
}
