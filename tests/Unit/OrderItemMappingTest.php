<?php

namespace App\Tests\Unit;

use App\Entity\OrderItem;
use App\Entity\Order;
use App\Entity\Product;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class OrderItemMappingTest extends TestCase
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
        $this->meta = $em->getClassMetadata(OrderItem::class);
    }

    public function testTableName(): void
    {
        $this->assertSame('order_items', $this->meta->getTableName());
    }

    public function testQuantityColumn(): void
    {
        $this->assertSame('quantity', $this->meta->getColumnName('quantity'));
        $this->assertSame('integer', $this->meta->getTypeOfField('quantity'));
    }

    public function testPriceColumn(): void
    {
        $this->assertSame('price', $this->meta->getColumnName('price'));
        $this->assertSame('decimal', $this->meta->getTypeOfField('price'));
        $mapping = $this->meta->getFieldMapping('price');
        $this->assertSame(10, $mapping->precision);
        $this->assertSame(2, $mapping->scale);
    }

    public function testOrderAssociation(): void
    {
        $this->assertTrue($this->meta->hasAssociation('order'));
        $assoc = $this->meta->getAssociationMapping('order');
        $this->assertSame(ClassMetadata::MANY_TO_ONE, $assoc->type());
        $this->assertSame(Order::class, $assoc->targetEntity);
        // Check join column
        $joinColumns = $assoc->joinColumns ?? [];
        $this->assertNotEmpty($joinColumns);
        $joinCol = is_array($joinColumns[0]) ? (object) $joinColumns[0] : $joinColumns[0];
        $this->assertSame('order_id', $joinCol->name ?? $joinCol['name'] ?? null);
    }

    public function testProductAssociation(): void
    {
        $this->assertTrue($this->meta->hasAssociation('product'));
        $assoc = $this->meta->getAssociationMapping('product');
        $this->assertSame(ClassMetadata::MANY_TO_ONE, $assoc->type());
        $this->assertSame(Product::class, $assoc->targetEntity);
        // Check join column
        $joinColumns = $assoc->joinColumns ?? [];
        $this->assertNotEmpty($joinColumns);
        $joinCol = is_array($joinColumns[0]) ? (object) $joinColumns[0] : $joinColumns[0];
        $this->assertSame('product_id', $joinCol->name ?? $joinCol['name'] ?? null);
    }
}
