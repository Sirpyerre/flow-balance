<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Clock;

interface Clock
{
    public function now(): int;
}
