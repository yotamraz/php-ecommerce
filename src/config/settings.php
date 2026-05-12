<?php

declare(strict_types=1);

use App\Config\Config;

return static function (Config $config): array {
    return [
        'displayErrorDetails' => (bool) (getenv('APP_DEBUG') ?: false),
        'logErrors' => true,
        'logErrorDetails' => true,
        'database' => [
            'dsn' => $config->getDsn(),
            'username' => $config->dbUser,
            'password' => $config->dbPass,
        ],
        'redis' => [
            'host' => $config->redisHost,
            'port' => $config->redisPort,
        ],
        'rabbitmq' => [
            'host' => $config->rabbitmqHost,
            'port' => $config->rabbitmqPort,
            'user' => $config->rabbitmqUser,
            'pass' => $config->rabbitmqPass,
        ],
    ];
};
