<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use InvalidArgumentException;

final readonly class ClientPaidAmount
{
    public string $value;

    public function __construct(mixed $value, bool $allowZero = false)
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{1,14}(?:\.[0-9]{1,2})?\z/', $value)
            || (!$allowZero && trim($value, '0.') === '')) {
            throw new InvalidArgumentException('Expected a positive decimal amount with at most two decimal places');
        }
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $this->value = (ltrim($integer, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
    }
}
