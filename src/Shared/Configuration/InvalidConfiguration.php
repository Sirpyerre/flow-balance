<?php

declare(strict_types=1);

namespace FlowBalance\Shared\Configuration;

use InvalidArgumentException;

/** A setting is missing or invalid. Messages name the setting and never carry its value. */
final class InvalidConfiguration extends InvalidArgumentException {}
