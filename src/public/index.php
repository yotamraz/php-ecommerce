<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Router;
use App\Cache;
use App\Queue;

header('Content-Type: application/json');

// Bootstrap services
$em    = require __DIR__ . '/../config/doctrine.php';
$cache = Cache::connect();
$queue = Queue::connect();

// Simple router
$router = new Router($em, $cache, $queue);
$router->handleRequest();
