<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use FlowBalance\Shared\Configuration\AppSettings;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
return AppSettings::fromEnvironment(array_replace($_ENV, $_SERVER, getenv()));
