<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes domain events to RabbitMQ.
 * Replaces the static Queue::publish() method with a DI-friendly service.
 * Creates connection lazily to avoid failing app startup if RabbitMQ is unavailable.
 */
class EventPublisher
{
    private ?AMQPStreamConnection $connection = null;

    public function __construct(
        private Config $config,
    ) {}

    /**
     * Publish an event message to a named queue.
     *
     * @param string $queueName The queue to publish to
     * @param array<string, mixed> $data The event payload
     * @throws \RuntimeException If connection fails
     */
    public function publish(string $queueName, array $data): void
    {
        $conn = $this->getConnection();
        $channel = $conn->channel();
        $channel->queue_declare($queueName, false, true, false, false);

        $message = new AMQPMessage(
            json_encode($data),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        $channel->basic_publish($message, '', $queueName);
        $channel->close();
    }

    /**
     * Check if a connection to RabbitMQ can be established.
     */
    public function isConnected(): bool
    {
        try {
            return $this->getConnection()->isConnected();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Lazily create the AMQP connection.
     */
    private function getConnection(): AMQPStreamConnection
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connection = new AMQPStreamConnection(
                $this->config->rabbitmqHost,
                $this->config->rabbitmqPort,
                $this->config->rabbitmqUser,
                $this->config->rabbitmqPass,
            );
        }
        return $this->connection;
    }
}
