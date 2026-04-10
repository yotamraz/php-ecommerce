<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Router;
use App\Database;
use App\Cache;
use App\Queue;

header('Content-Type: application/json');

// Bootstrap services
$db = Database::connect();
$cache = Cache::connect();
$queue = Queue::connect();

// Simple router
$router = new Router($db, $cache, $queue);
$router->handleRequest();
