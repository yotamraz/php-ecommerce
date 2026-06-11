<?php

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;

require_once __DIR__ . '/../vendor/autoload.php';

$isDevMode = (getenv('APP_ENV') ?: 'production') === 'development';

$config = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/../app/Entity'],
    isDevMode: $isDevMode,
);

$connection = DriverManager::getConnection([
    'driver'   => 'pdo_mysql',
    'host'     => getenv('DB_HOST') ?: 'mysql',
    'dbname'   => getenv('DB_NAME') ?: 'ecommerce',
    'user'     => getenv('DB_USER') ?: 'ecommerce',
    'password' => getenv('DB_PASS') ?: 'ecommerce',
    'charset'  => 'utf8mb4',
], $config);

return new EntityManager($connection, $config);
