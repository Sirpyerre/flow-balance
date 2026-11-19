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
        if ($legacy && $payment['currency'] !== 'MXN') {
            throw new IncomeValidation('currency', 'Abre el formulario completo para corregir el cobro.');
        }
        $attempt = null;
        $original = $payment;
        if (!$legacy) {
            $key = PaymentInput::key($key);
            $attempt = $this->repository->attempt($payment, $key);
            if ($attempt !== null) {
                $original = [...$payment, ...json_decode($attempt['previous_values'], true, flags: JSON_THROW_ON_ERROR)];
            } elseif ($version !== $payment['version']) {
                throw new PaymentVersionConflict();
            }
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
            if (array_diff(array_keys($body), ['clientPaidAmount', 'platformFee', 'withheldTaxAmount', 'paidOn', 'sourceType', 'sourceName', 'excessReason', 'currency', 'exchangeRate', 'confirmCurrencyChange'])) {
                throw new IncomeValidation('payment', 'Revisa los campos indicados.');
            }
            [$source, $name] = PaymentInput::source($body, allowHistorical: true);
            $reason = PaymentInput::optionalText($body['excessReason'] ?? null, 'excessReason');
            $fields += [
                'platform_fee' => $amounts->platformFee, 'withheld_tax_amount' => $amounts->withheldTaxAmount,
                'paid_on' => PaymentInput::date($body['paidOn'] ?? null),
                'source_type' => $source, 'source_name' => $name,
            ];
            [$currency, $rate] = $this->conversion($body, $original);
            $changedCurrency = $currency !== $original['currency'];
            if ($changedCurrency || $currency === 'USD') {
                $fields += ['currency' => $currency, 'exchange_rate' => $rate];
            }
            $fingerprint = hash('sha256', json_encode([$version, $fields, $reason], JSON_THROW_ON_ERROR));
            if ($attempt !== null) {
                if (!hash_equals($attempt['fingerprint'], $fingerprint)) {
                    throw new DomainException('La clave ya se usó para otra corrección.');
                }
                if ($attempt['result_version'] === $payment['version']) {
                    return;
                }
                throw new PaymentVersionConflict();
            }
        }
        $totals = $this->repository->totals($payment, $amounts, $currency ?? 'MXN', $rate ?? null);
        if (self::increasesExcess($payment['agreed_amount'], $totals['previous'], $totals['projected']) && $reason === null) {
            throw new IncomeValidation('excessReason', 'Indica por qué aumenta el excedente del proyecto.');
        }
        if ($reason !== null) {
            $fields['excess_reason'] = $reason;
        }
        $this->repository->change($payment, 'edit', $fields, $reason, $legacy ? null : $key, $fingerprint);
    }

    public function preview(string $userId, string $id, array $body, string $version): array
    {
        $payment = $this->repository->lock($userId, $id);
        if ($payment['deleted_at'] !== null) {
            throw new IncomeNotFound();
        }
        if ($version !== $payment['version']) {
            throw new PaymentVersionConflict();
        }
        if (array_diff(array_keys($body), ['clientPaidAmount', 'platformFee', 'withheldTaxAmount', 'currency', 'exchangeRate', 'confirmCurrencyChange'])) {
            throw new IncomeValidation('payment', 'Revisa los campos indicados.');
        }
        $amounts = new PaymentAmounts($body['clientPaidAmount'] ?? null, $body['platformFee'] ?? null, $body['withheldTaxAmount'] ?? null);
        [$currency, $rate] = $this->conversion($body, $payment);
        $totals = $this->repository->totals($payment, $amounts, $currency, $rate);
        $balance = ProjectBalance::calculate($payment['agreed_amount'], $totals['projected']);

        return [
            'receivedAmount' => $amounts->receivedAmount,
            'projectedOutstandingBalance' => $balance['outstandingBalance'],
            'excessAmount' => $balance['excessAmount'],
            'requiresExcessReason' => self::increasesExcess($payment['agreed_amount'], $totals['previous'], $totals['projected']),
            'isBalanceProvisional' => (bool) $totals['provisional'],
        ];
    }

    private function conversion(array $body, array $original): array
    {
        $currency = $body['currency'] ?? $original['currency'];
        if (!in_array($currency, ['MXN', 'USD'], true) || !in_array($original['currency'], ['MXN', 'USD'], true)) {
            throw new IncomeValidation('currency', 'Selecciona MXN o USD.');
        }
        $changedCurrency = $currency !== $original['currency'];
        if ($changedCurrency && ($body['confirmCurrencyChange'] ?? false) !== true) {
            throw new IncomeValidation('currency', 'Confirma los importes en la moneda de destino.');
        }
        $rate = PaymentConversion::rate($currency, $changedCurrency
            ? ($body['exchangeRate'] ?? null)
            : (array_key_exists('exchangeRate', $body) ? $body['exchangeRate'] : ($original['exchange_rate'] ?? null)));
        return [$currency, $rate];
    }

    public static function increasesExcess(?string $agreement, string $previous, string $projected): bool
    {
        if (ProjectBalance::calculate($agreement, $projected)['collectionStatus'] !== 'overpaid') {
            return false;
        }
        return ProjectBalance::calculate($previous, $projected)['collectionStatus'] === 'overpaid';
    }
}
