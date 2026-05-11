<?php

declare(strict_types=1);

use App\Config\Config;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\EventPublisher;
use App\Services\OrderService;
use App\Services\ProductService;
use DI\ContainerBuilder;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Psr\Container\ContainerInterface;

return static function (ContainerBuilder $containerBuilder): void {
    $containerBuilder->addDefinitions([
        // Configuration
        Config::class => \DI\create(Config::class),

        // Database (PDO) — will be replaced by Doctrine DBAL in milestone 3
        PDO::class => static function (ContainerInterface $c): PDO {
            $config = $c->get(Config::class);
            return new PDO(
                $config->getDsn(),
                $config->dbUser,
                $config->dbPass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        },

        // Redis cache
        RedisClient::class => static function (ContainerInterface $c): RedisClient {
            $config = $c->get(Config::class);
            return new RedisClient([
                'scheme' => 'tcp',
                'host' => $config->redisHost,
                'port' => $config->redisPort,
            ]);
        },

        // RabbitMQ connection
        AMQPStreamConnection::class => static function (ContainerInterface $c): AMQPStreamConnection {
            $config = $c->get(Config::class);
            return new AMQPStreamConnection(
                $config->rabbitmqHost,
                $config->rabbitmqPort,
                $config->rabbitmqUser,
                $config->rabbitmqPass,
            );
        },

        // Event publisher (wraps RabbitMQ)
        EventPublisher::class => static function (ContainerInterface $c): EventPublisher {
            return new EventPublisher($c->get(AMQPStreamConnection::class));
        },

        // Repositories
        ProductRepository::class => static function (ContainerInterface $c): ProductRepository {
            return new ProductRepository($c->get(PDO::class));
        },

        OrderRepository::class => static function (ContainerInterface $c): OrderRepository {
            return new OrderRepository($c->get(PDO::class));
        },

        // Services
        ProductService::class => static function (ContainerInterface $c): ProductService {
            return new ProductService(
                $c->get(ProductRepository::class),
                $c->get(RedisClient::class),
            );
        },

        OrderService::class => static function (ContainerInterface $c): OrderService {
            return new OrderService(
                $c->get(OrderRepository::class),
                $c->get(ProductRepository::class),
                $c->get(RedisClient::class),
                $c->get(EventPublisher::class),
            );
        },
    ]);
};
