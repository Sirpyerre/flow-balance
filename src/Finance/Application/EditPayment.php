<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use DomainException;
use FlowBalance\Finance\Infrastructure\PostgresPaymentCorrectionRepository;

final readonly class EditPayment
{
    public function __construct(private PostgresPaymentCorrectionRepository $repository) {}

    public function edit(string $userId, string $id, array $body, string $version, string $key): void
    {
        $payment = $this->repository->lock($userId, $id);
        if ($payment['deleted_at'] !== null) {
            throw new IncomeNotFound();
        }
        $legacy = $version === '' && $key === '' && array_keys($body) === ['clientPaidAmount'];
        if (!$legacy && !OwnedResourceChanges::identifier($version)) {
            throw new IncomeValidation('version', 'Vuelve a abrir el cobro antes de editarlo.');
        }
        $amounts = new PaymentAmounts(
            $body['clientPaidAmount'] ?? null,
            $legacy ? $payment['platform_fee'] : ($body['platformFee'] ?? null),
            $legacy ? $payment['withheld_tax_amount'] : ($body['withheldTaxAmount'] ?? null),
        );
        $fields = ['client_paid_amount' => $amounts->clientPaidAmount];
        $reason = null;
        $fingerprint = null;
        if (!$legacy) {
            if (array_diff(array_keys($body), ['clientPaidAmount', 'platformFee', 'withheldTaxAmount', 'paidOn', 'sourceType', 'sourceName', 'excessReason'])) {
                throw new IncomeValidation('payment', 'Revisa los campos indicados.');
            }
            [$source, $name] = PaymentInput::source($body, allowHistorical: true);
            $reason = PaymentInput::optionalText($body['excessReason'] ?? null, 'excessReason');
            $fields += [
                'platform_fee' => $amounts->platformFee, 'withheld_tax_amount' => $amounts->withheldTaxAmount,
                'paid_on' => PaymentInput::date($body['paidOn'] ?? null),
                'source_type' => $source, 'source_name' => $name,
            ];
            $key = PaymentInput::key($key);
            $fingerprint = hash('sha256', json_encode([$version, $fields, $reason], JSON_THROW_ON_ERROR));
            $attempt = $this->repository->attempt($payment, $key);
            if ($attempt !== null) {
                if (!hash_equals($attempt['fingerprint'], $fingerprint)) {
                    throw new DomainException('La clave ya se usó para otra corrección.');
                }
                if ($attempt['result_version'] === $payment['version']) {
                    return;
                }
                throw new PaymentVersionConflict();
            }
            if ($version !== $payment['version']) {
                throw new PaymentVersionConflict();
            }
        }
        $totals = $this->repository->totals($payment, $fields['client_paid_amount']);
        if (self::increasesExcess($payment['agreed_amount'], $totals['previous'], $totals['projected']) && $reason === null) {
            throw new IncomeValidation('excessReason', 'Indica por qué aumenta el excedente del proyecto.');
        }
        if ($reason !== null) {
            $fields['excess_reason'] = $reason;
        }
        $this->repository->change($payment, 'edit', $fields, $reason, $legacy ? null : $key, $fingerprint);
    }

    public static function increasesExcess(?string $agreement, string $previous, string $projected): bool
    {
        if (ProjectBalance::calculate($agreement, $projected)['collectionStatus'] !== 'overpaid') {
            return false;
        }
        return ProjectBalance::calculate($previous, $projected)['collectionStatus'] === 'overpaid';
    }
}
