<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use FlowBalance\Finance\Infrastructure\PostgresPaymentCorrectionRepository;

final readonly class DeletePayment
{
    public function __construct(private PostgresPaymentCorrectionRepository $repository) {}

    public function delete(string $userId, string $id, string $version, array $body): bool
    {
        $payment = $this->repository->lock($userId, $id);
        if ($version === '') {
            return false;
        }
        $deleted = $payment['deleted_at'] !== null;
        $expectedVersion = $deleted ? (string) ((int) $payment['version'] - 1) : $payment['version'];
        if ($version !== '"' . $expectedVersion . '"') {
            throw new PaymentVersionConflict();
        }
        if ($deleted) {
            return true;
        }
        if (array_diff(array_keys($body), ['reason'])) {
            throw new IncomeValidation('reason', 'Revisa los campos indicados.');
        }
        $reason = PaymentInput::optionalText($body['reason'] ?? null, 'reason');
        $this->repository->change($payment, 'delete', [], $reason);
        return true;
    }
}
