<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var \Kongono\Http\App $app */
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->run();
