<?php

namespace App;

use Predis\Client as RedisClient;

class Cache
{
    private static ?RedisClient $instance = null;

    public static function connect(): RedisClient
    {
        if (self::$instance === null) {
            $host = getenv('REDIS_HOST') ?: 'redis';
            $port = getenv('REDIS_PORT') ?: 6379;

            self::$instance = new RedisClient([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => (int) $port,
            ]);
        }

        return self::$instance;
    }
}
