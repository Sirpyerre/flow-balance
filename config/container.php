<?php

declare(strict_types=1);

use Laminas\ServiceManager\ServiceManager;

$config = array_replace_recursive(
    (new Mezzio\ConfigProvider())(),
    (new Mezzio\Router\ConfigProvider())(),
    (new Mezzio\Router\FastRouteRouter\ConfigProvider())(),
);
$container = new ServiceManager($config['dependencies']);
$container->setService('config', $config);

return $container;
