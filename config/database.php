<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use FlowBalance\Shared\Database\DatabaseConfig;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
return DatabaseConfig::fromEnvironment(array_replace($_ENV, $_SERVER, getenv()));
