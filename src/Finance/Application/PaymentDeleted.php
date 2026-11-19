<?php

declare(strict_types=1);

namespace FlowBalance\Finance\Application;

final class PaymentDeleted extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Este cobro ya fue eliminado.');
    }
}
