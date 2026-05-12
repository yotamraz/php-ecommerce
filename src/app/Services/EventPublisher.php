<?php

declare(strict_types=1);

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publishes domain events to RabbitMQ.
 * Replaces the static Queue::publish() method with a DI-friendly service.
 */
class EventPublisher
{
    public function __construct(
        private ?AMQPStreamConnection $connection,
    ) {}

    /**
     * Publish an event message to a named queue.
     *
     * @param string $queueName The queue to publish to
     * @param array<string, mixed> $data The event payload
     */
    public function publish(string $queueName, array $data): void
    {
        if ($this->connection === null) {
            // RabbitMQ not available — skip publishing silently
            return;
        }

        $channel = $this->connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);

        $message = new AMQPMessage(
            json_encode($data),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        $channel->basic_publish($message, '', $queueName);
        $channel->close();
    }
}
