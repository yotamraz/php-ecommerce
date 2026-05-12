<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/**
 * Typed configuration class that validates all required environment variables at boot.
 * Uses PHP 8.4 asymmetric visibility for read-only public access.
 */
class Config
{
    // Database
    public private(set) string $dbHost;
    public private(set) string $dbName;
    public private(set) string $dbUser;
    public private(set) string $dbPass;
    public private(set) int $dbPort;

    // Redis
    public private(set) string $redisHost;
    public private(set) int $redisPort;

    // RabbitMQ
    public private(set) string $rabbitmqHost;
    public private(set) int $rabbitmqPort;
    public private(set) string $rabbitmqUser;
    public private(set) string $rabbitmqPass;

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
