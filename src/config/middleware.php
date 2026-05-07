<?php

declare(strict_types=1);

use App\Middleware\ErrorHandler;
use App\Middleware\JsonContentType;
use Slim\App;

return function (App $app): void {
    // Error handling middleware (outermost — catches all exceptions)
    $app->add(new ErrorHandler());

    // Set Content-Type: application/json on every response
    $app->add(new JsonContentType());
};
