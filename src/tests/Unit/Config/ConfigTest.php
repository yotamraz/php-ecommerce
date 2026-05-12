<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any env vars that might interfere
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PORT', 'REDIS_HOST', 'REDIS_PORT', 'RABBITMQ_HOST', 'RABBITMQ_PORT', 'RABBITMQ_USER', 'RABBITMQ_PASS'] as $var) {
            putenv("{$var}");
        }
    }

    public function testDefaultValues(): void
    {
        $config = new Config();

        $this->assertEquals('mysql', $config->dbHost);
        $this->assertEquals('ecommerce', $config->dbName);
        $this->assertEquals('ecommerce', $config->dbUser);
        $this->assertEquals('ecommerce', $config->dbPass);
        $this->assertEquals(3306, $config->dbPort);
        $this->assertEquals('redis', $config->redisHost);
        $this->assertEquals(6379, $config->redisPort);
        $this->assertEquals('rabbitmq', $config->rabbitmqHost);
        $this->assertEquals(5672, $config->rabbitmqPort);
        $this->assertEquals('guest', $config->rabbitmqUser);
        $this->assertEquals('guest', $config->rabbitmqPass);
    }

    public function testCustomEnvValues(): void
    {
        putenv('DB_HOST=custom-host');
        putenv('DB_NAME=custom-db');
        putenv('REDIS_PORT=6380');

        $config = new Config();

        $this->assertEquals('custom-host', $config->dbHost);
        $this->assertEquals('custom-db', $config->dbName);
        $this->assertEquals(6380, $config->redisPort);
    }

    public function testGetDsn(): void
    {
        $config = new Config();
        $dsn = $config->getDsn();

        $this->assertStringContainsString('mysql:host=mysql', $dsn);
        $this->assertStringContainsString('dbname=ecommerce', $dsn);
        $this->assertStringContainsString('charset=utf8mb4', $dsn);
    }

    public function testReadonlyPropertiesArePubliclyReadable(): void
    {
        $config = new Config();

        // Verify properties are publicly readable
        $this->assertIsString($config->dbHost);

        // Verify readonly prevents external mutation
        $reflection = new \ReflectionProperty(Config::class, 'dbHost');
        $this->assertTrue($reflection->isPublic());
        $this->assertTrue($reflection->isReadOnly());
    }
}
