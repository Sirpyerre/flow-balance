<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use FlowBalance\Finance\Infrastructure\SqlOwnedResourceRepository;

final class ProjectAgreement
{
    public static function paymentCount(mixed $count): int
    {
        if (!is_int($count) || $count < 1 || $count > 9007199254740991) {
            throw new IncomeValidation('agreedPaymentCount', 'Indica una cantidad entera de pagos mayor que cero.');
        }
        return $count;
    }

    public static function recordAdjustment(SqlOwnedResourceRepository $repository, string $userId, string $projectId, string $amount, mixed $reason): void
    {
        $project = $repository->detail('projects', $userId, $projectId);
        $preview = self::preview($project, $amount);
        if (!$preview['requiresAdjustmentReason']) {
            return;
        }

        try {
            $reason = PaymentInput::optionalText($reason, 'agreementAdjustmentReason');
        } catch (IncomeValidation $error) {
            throw new IncomeValidation($error->field, $error->getMessage(), ['excessAmount' => $preview['excessAmount']]);
        }
        if ($reason === null) {
            throw new IncomeValidation('agreementAdjustmentReason', 'Indica por qué se ajusta el acuerdo con excedente.',
                ['excessAmount' => $preview['excessAmount']]);
        }
        $repository->recordAgreementRevision($userId, $projectId, $project['agreedAmount'], $amount, $preview, $reason);
    }

    public static function preview(array $project, mixed $proposed): array
    {
        $amount = PaymentAmounts::normalize($proposed, 'agreedAmount');
        $balance = ProjectBalance::calculate($amount, $project['clientPaidTotal']);
        $previous = $project['agreedAmount'];
        return [
            ...$balance,
            'clientPaidTotal' => $project['clientPaidTotal'],
            'isBalanceProvisional' => $project['unconvertedPaymentCount'] > 0,
            'requiresAdjustmentReason' => $previous !== null && $balance['collectionStatus'] === 'overpaid'
                && ProjectBalance::calculate($previous, $amount)['collectionStatus'] === 'outstanding',
        ];
    }
}
