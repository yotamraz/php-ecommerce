<?php

namespace App\Tests\Unit;

use App\Entity\Product;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class ProductMappingTest extends TestCase
{
    private ClassMetadata $meta;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/../../src/app/Entity'],
            isDevMode: true,
        );
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = EntityManager::create($conn, $config);
        $this->meta = $em->getClassMetadata(Product::class);
    }

    public function testTableName(): void
    {
        $this->assertSame('products', $this->meta->getTableName());
    }

    public function testPrimaryKey(): void
    {
        $this->assertSame(['id'], $this->meta->getIdentifierFieldNames());
        $this->assertSame('integer', $this->meta->getTypeOfField('id'));
    }

    public function testNameColumn(): void
    {
        $this->assertSame('name', $this->meta->getColumnName('name'));
        $this->assertSame('string', $this->meta->getTypeOfField('name'));
        $this->assertSame(255, $this->meta->getFieldMapping('name')->length);
    }

    public function testDescriptionColumn(): void
    {
        $this->assertSame('description', $this->meta->getColumnName('description'));
        $this->assertTrue($this->meta->isNullable('description'));
    }

    public function testPriceColumn(): void
    {
        $this->assertSame('price', $this->meta->getColumnName('price'));
        $this->assertSame('decimal', $this->meta->getTypeOfField('price'));
        $mapping = $this->meta->getFieldMapping('price');
        $this->assertSame(10, $mapping->precision);
        $this->assertSame(2, $mapping->scale);
    }

    public function testStockColumn(): void
    {
        $this->assertSame('stock', $this->meta->getColumnName('stock'));
        $this->assertSame('integer', $this->meta->getTypeOfField('stock'));
    }

    public function testTimestampColumns(): void
    {
        $this->assertSame('created_at', $this->meta->getColumnName('createdAt'));
        $this->assertSame('updated_at', $this->meta->getColumnName('updatedAt'));
        $this->assertSame('datetime_immutable', $this->meta->getTypeOfField('createdAt'));
        $this->assertSame('datetime_immutable', $this->meta->getTypeOfField('updatedAt'));
    }

    public function testOrderItemsAssociation(): void
    {
        $this->assertTrue($this->meta->hasAssociation('orderItems'));
        $assoc = $this->meta->getAssociationMapping('orderItems');
        $this->assertSame(ClassMetadata::ONE_TO_MANY, $assoc->type());
    }
}
