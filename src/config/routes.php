<?php

declare(strict_types=1);

use App\Controller\HealthController;
use App\Controller\OrderController;
use App\Controller\ProductController;
use Slim\App;

return function (App $app): void {
    // Health check
    $app->get('/health', [HealthController::class, 'health']);

    // Products CRUD
    $app->get('/api/products', [ProductController::class, 'list']);
    $app->get('/api/products/{id:[0-9]+}', [ProductController::class, 'get']);
    $app->post('/api/products', [ProductController::class, 'create']);
    $app->put('/api/products/{id:[0-9]+}', [ProductController::class, 'update']);
    $app->delete('/api/products/{id:[0-9]+}', [ProductController::class, 'delete']);

    // Orders (delegated to legacy Router logic for now — will be modernized in Milestone 2)
    $app->get('/api/orders', [OrderController::class, 'list']);
    $app->get('/api/orders/{id:[0-9]+}', [OrderController::class, 'get']);
    $app->post('/api/orders', [OrderController::class, 'create']);
};
