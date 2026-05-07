<?php

declare(strict_types=1);

use App\Repository\ProductRepository;
use App\Service\ProductService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Predis\Client as RedisClient;
use Psr\Container\ContainerInterface;

return [
    // --- Infrastructure ---

    PDO::class => function (): PDO {
        $host = $_ENV['DB_HOST'] ?? 'mysql';
        $db   = $_ENV['DB_NAME'] ?? 'ecommerce';
        $user = $_ENV['DB_USER'] ?? 'ecommerce';
        $pass = $_ENV['DB_PASS'] ?? 'ecommerce';

        return new PDO(
            "mysql:host={$host};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    },

    RedisClient::class => function (): RedisClient {
        $host = $_ENV['REDIS_HOST'] ?? 'redis';
        $port = $_ENV['REDIS_PORT'] ?? 6379;

        return new RedisClient([
            'scheme' => 'tcp',
            'host'   => $host,
            'port'   => (int) $port,
        ]);
    },

    AMQPStreamConnection::class => function (): AMQPStreamConnection {
        $host = $_ENV['RABBITMQ_HOST'] ?? 'rabbitmq';
        $port = $_ENV['RABBITMQ_PORT'] ?? 5672;
        $user = $_ENV['RABBITMQ_USER'] ?? 'guest';
        $pass = $_ENV['RABBITMQ_PASS'] ?? 'guest';

        return new AMQPStreamConnection($host, (int) $port, $user, $pass);
    },

    // --- Repositories (autowired, but explicit for clarity) ---

    ProductRepository::class => function (ContainerInterface $c): ProductRepository {
        return new ProductRepository($c->get(PDO::class));
    },

    // --- Services ---

    ProductService::class => function (ContainerInterface $c): ProductService {
        return new ProductService(
            $c->get(ProductRepository::class),
            $c->get(RedisClient::class),
        );
    },
];
