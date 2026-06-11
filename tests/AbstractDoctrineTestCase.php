<?php

namespace App\Tests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

abstract class AbstractDoctrineTestCase extends TestCase
{
    protected EntityManager $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../src/app/Entity'],
            isDevMode: true,
        );

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $this->em = EntityManager::create($connection, $config);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema(
            $this->em->getMetadataFactory()->getAllMetadata()
        );
    }

    protected function tearDown(): void
    {
        $this->em->close();
    }
}
