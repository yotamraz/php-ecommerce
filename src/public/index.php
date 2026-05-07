<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use Dotenv\Dotenv;

// Load environment variables from .env (if it exists)
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// Build DI container
$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(__DIR__ . '/../config/container.php');
$container = $containerBuilder->build();

// Create Slim app with PHP-DI bridge
$app = Bridge::create($container);

// Register middleware
$middleware = require __DIR__ . '/../config/middleware.php';
$middleware($app);

// Register routes
$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

// Run
$app->run();
