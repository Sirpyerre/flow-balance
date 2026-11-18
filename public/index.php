<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$container = require dirname(__DIR__) . '/config/container.php';
$app = $container->get(Mezzio\Application::class);
(require dirname(__DIR__) . '/config/pipeline.php')($app, $container->get(Mezzio\MiddlewareFactory::class));
(require dirname(__DIR__) . '/config/routes.php')($app);
$app->run();
