<?php

declare(strict_types=1);

namespace App\Controllers;

use PDO;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Health check endpoint that reports connectivity status of all backing services.
 */
class HealthController
{
    public function __construct(
        private PDO $db,
        private RedisClient $cache,
        private ?AMQPStreamConnection $queue,
    ) {}

    /**
     * GET /health
     */
    public function health(Request $request, Response $response): Response
    {
        $status = ['status' => 'ok', 'services' => []];

        // MySQL
        try {
            $this->db->query('SELECT 1');
            $status['services']['mysql'] = 'connected';
        } catch (\Exception $e) {
            $status['services']['mysql'] = 'error';
            $status['status'] = 'degraded';
        }

        // Redis
        try {
            $this->cache->ping();
            $status['services']['redis'] = 'connected';
        } catch (\Exception $e) {
            $status['services']['redis'] = 'error';
            $status['status'] = 'degraded';
        }

        // RabbitMQ
        try {
            if ($this->queue !== null && $this->queue->isConnected()) {
                $status['services']['rabbitmq'] = 'connected';
            } else {
                $status['services']['rabbitmq'] = 'error';
                $status['status'] = 'degraded';
            }
        } catch (\Exception $e) {
            $status['services']['rabbitmq'] = 'error';
            $status['status'] = 'degraded';
        }

        $response->getBody()->write(json_encode($status));
        return $response;
    }
}
