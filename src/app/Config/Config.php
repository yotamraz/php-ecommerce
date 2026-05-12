<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Typed configuration class that validates all required environment variables at boot.
 * Uses readonly properties for immutability after construction.
 */
class Config
{
    // Database
    public readonly string $dbHost;
    public readonly string $dbName;
    public readonly string $dbUser;
    public readonly string $dbPass;
    public readonly int $dbPort;

    // Redis
    public readonly string $redisHost;
    public readonly int $redisPort;

    // RabbitMQ
    public readonly string $rabbitmqHost;
    public readonly int $rabbitmqPort;
    public readonly string $rabbitmqUser;
    public readonly string $rabbitmqPass;

    public function __construct()
    {
        $this->dbHost = $this->requireEnv('DB_HOST', 'mysql');
        $this->dbName = $this->requireEnv('DB_NAME', 'ecommerce');
        $this->dbUser = $this->requireEnv('DB_USER', 'ecommerce');
        $this->dbPass = $this->requireEnv('DB_PASS', 'ecommerce');
        $this->dbPort = (int) $this->requireEnv('DB_PORT', '3306');

        $this->redisHost = $this->requireEnv('REDIS_HOST', 'redis');
        $this->redisPort = (int) $this->requireEnv('REDIS_PORT', '6379');

        $this->rabbitmqHost = $this->requireEnv('RABBITMQ_HOST', 'rabbitmq');
        $this->rabbitmqPort = (int) $this->requireEnv('RABBITMQ_PORT', '5672');
        $this->rabbitmqUser = $this->requireEnv('RABBITMQ_USER', 'guest');
        $this->rabbitmqPass = $this->requireEnv('RABBITMQ_PASS', 'guest');
    }

    /**
     * Get an environment variable value, falling back to a default if provided.
     * Throws if no value and no default.
     */
    private function requireEnv(string $name, ?string $default = null): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            if ($default !== null) {
                return $default;
            }
            throw new RuntimeException("Required environment variable '{$name}' is not set.");
        }

        return $value;
    }

    /**
     * Get the PDO DSN string for MySQL connection.
     */
    public function getDsn(): string
    {
        return "mysql:host={$this->dbHost};port={$this->dbPort};dbname={$this->dbName};charset=utf8mb4";
    }
}
