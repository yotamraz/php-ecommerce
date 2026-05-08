<?php

declare(strict_types=1);

use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\JsonResponseMiddleware;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env file if it exists (development only)
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

// Build DI container
$containerBuilder = new ContainerBuilder();
$containerDefinitions = require __DIR__ . '/../config/container.php';
$containerDefinitions($containerBuilder);
$container = $containerBuilder->build();

// Create Slim app with PHP-DI
$app = AppFactory::createFromContainer($container);

// Add middleware (LIFO order — last added runs first)
$app->addRoutingMiddleware();
$app->add(new JsonResponseMiddleware());
$app->add(new ErrorHandlerMiddleware());

// Register routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

// Run the app
$app->run();
