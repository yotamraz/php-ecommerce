<?php

namespace App;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Queue
{
    private static ?AMQPStreamConnection $instance = null;

    public static function connect(): AMQPStreamConnection
    {
        if (self::$instance === null) {
            $host = getenv('RABBITMQ_HOST') ?: 'rabbitmq';
            $port = getenv('RABBITMQ_PORT') ?: 5672;
            $user = getenv('RABBITMQ_USER') ?: 'guest';
            $pass = getenv('RABBITMQ_PASS') ?: 'guest';

            self::$instance = new AMQPStreamConnection($host, (int) $port, $user, $pass);
        }

        return self::$instance;
    }

    public static function publish(string $queueName, array $data): void
    {
        $connection = self::connect();
        $channel = $connection->channel();
        $channel->queue_declare($queueName, false, true, false, false);

        $message = new AMQPMessage(
            json_encode($data),
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        $channel->basic_publish($message, '', $queueName);
        $channel->close();
    }
}
