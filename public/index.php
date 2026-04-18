<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Daems\Infrastructure\Framework\Http\Request;

$kernel = require dirname(__DIR__) . '/bootstrap/app.php';

$kernel->send(
    $kernel->handle(Request::fromGlobals())
);
