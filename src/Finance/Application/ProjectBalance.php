<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

final class ProjectBalance
{
    /** @return array{outstandingBalance: ?string, excessAmount: ?string, collectionStatus: string} */
    public static function calculate(?string $agreedAmount, string $clientPaidTotal): array
    {
        if ($agreedAmount === null) {
            return ['outstandingBalance' => null, 'excessAmount' => null, 'collectionStatus' => 'undetermined'];
        }

        $agreed = str_replace('.', '', $agreedAmount);
        $paid = str_replace('.', '', $clientPaidTotal);
        $length = max(strlen($agreed), strlen($paid));
        $agreed = str_pad($agreed, $length, '0', STR_PAD_LEFT);
        $paid = str_pad($paid, $length, '0', STR_PAD_LEFT);
        $order = strcmp($agreed, $paid);

        return [
            'outstandingBalance' => $order > 0 ? self::difference($agreed, $paid) : '0.00',
            'excessAmount' => $order < 0 ? self::difference($paid, $agreed) : '0.00',
            'collectionStatus' => $order === 0 ? 'settled' : ($order > 0 ? 'outstanding' : 'overpaid'),
        ];
    }

    private static function difference(string $greater, string $lesser): string
    {
        $borrow = 0;
        $digits = $greater;
        for ($index = strlen($greater) - 1; $index >= 0; --$index) {
            $digit = (int) $greater[$index] - (int) $lesser[$index] - $borrow;
            $borrow = (int) ($digit < 0);
            $digits[$index] = (string) ($digit + 10 * $borrow);
        }
        return (ltrim(substr($digits, 0, -2), '0') ?: '0') . '.' . substr($digits, -2);
    }
}
