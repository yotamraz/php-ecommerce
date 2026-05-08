<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\ProductController;
use Slim\App;

return static function (App $app): void {
    // Health check
    $app->get('/health', [HealthController::class, 'health']);

    // Product routes
    $app->group('/api/products', function ($group) {
        $group->get('', [ProductController::class, 'list']);
        $group->get('/{id:[0-9]+}', [ProductController::class, 'get']);
        $group->post('', [ProductController::class, 'create']);
        $group->put('/{id:[0-9]+}', [ProductController::class, 'update']);
        $group->delete('/{id:[0-9]+}', [ProductController::class, 'delete']);
    });
};
