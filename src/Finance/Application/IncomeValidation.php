<?php
declare(strict_types=1);

namespace FlowBalance\Finance\Application;

use InvalidArgumentException;

final class IncomeValidation extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
