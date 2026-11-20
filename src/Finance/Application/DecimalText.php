<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

final class DecimalText
{
    /** Drops trailing zeros and a dangling point: "17.250000" becomes "17.25" and "4800.000000" becomes "4800". */
    public static function trimmed(?string $decimal): ?string
    {
        if ($decimal === null || !str_contains($decimal, '.')) {
            return $decimal;
        }
        return rtrim(rtrim($decimal, '0'), '.');
    }
}
