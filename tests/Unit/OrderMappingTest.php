<?php

namespace App\Tests\Unit;

use App\Entity\Order;
use App\Entity\OrderItem;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class OrderMappingTest extends TestCase
{
    private ClassMetadata $meta;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../src/app/Entity'],
            isDevMode: true,
        );
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($conn, $config);
        $this->meta = $em->getClassMetadata(Order::class);
    }

    public function testTableName(): void
    {
        $this->assertSame('orders', $this->meta->getTableName());
    }

    public function testStatusColumn(): void
    {
        $this->assertSame('status', $this->meta->getColumnName('status'));
        $this->assertSame('string', $this->meta->getTypeOfField('status'));
    }

    public function testTotalColumn(): void
    {
        $this->assertSame('total', $this->meta->getColumnName('total'));
        $this->assertSame('decimal', $this->meta->getTypeOfField('total'));
        $mapping = $this->meta->getFieldMapping('total');
        $this->assertSame(10, $mapping->precision);
        $this->assertSame(2, $mapping->scale);
    }

    public function testTimestampColumns(): void
    {
        $this->assertSame('created_at', $this->meta->getColumnName('createdAt'));
        $this->assertSame('updated_at', $this->meta->getColumnName('updatedAt'));
    }

    public function testItemsAssociationCascade(): void
    {
        $this->assertTrue($this->meta->hasAssociation('items'));
        $assoc = $this->meta->getAssociationMapping('items');
        $this->assertSame(ClassMetadata::ONE_TO_MANY, $assoc->type());
        $this->assertTrue(in_array('persist', $assoc->cascade ?? [], true));
        $this->assertTrue(in_array('remove', $assoc->cascade ?? [], true));
    }
}
