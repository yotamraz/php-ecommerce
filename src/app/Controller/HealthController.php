<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HealthController
{
    public function __construct(
        private readonly PDO $db,
        private readonly RedisClient $cache,
        private readonly AMQPStreamConnection $queue,
    ) {}

    public function health(Request $request, Response $response): Response
    {
        $status = ['status' => 'ok', 'services' => []];

        // MySQL
        try {
            $this->db->query('SELECT 1');
            $status['services']['mysql'] = 'connected';
        } catch (\Exception) {
            $status['services']['mysql'] = 'error';
            $status['status'] = 'degraded';
        }

        // Redis
        try {
            $this->cache->ping();
            $status['services']['redis'] = 'connected';
        } catch (\Exception) {
            $status['services']['redis'] = 'error';
            $status['status'] = 'degraded';
        }

        // RabbitMQ
        try {
            $this->queue->isConnected()
                ? $status['services']['rabbitmq'] = 'connected'
                : $status['services']['rabbitmq'] = 'error';
        } catch (\Exception) {
            $status['services']['rabbitmq'] = 'error';
            $status['status'] = 'degraded';
        }

        $response->getBody()->write(json_encode($status));

        return $response;
    }
}
