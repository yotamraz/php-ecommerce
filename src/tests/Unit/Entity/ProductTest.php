<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Product;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    private function sampleRow(): array
    {
        return [
            'id' => '1',
            'name' => 'Wireless Mouse',
            'description' => 'Ergonomic wireless mouse',
            'price' => '29.99',
            'stock' => '150',
            'created_at' => '2024-01-15 10:30:00',
            'updated_at' => '2024-01-15 10:30:00',
        ];
    }

    public function testFromRowCreatesProduct(): void
    {
        $product = Product::fromRow($this->sampleRow());

        $this->assertSame(1, $product->id);
        $this->assertSame('Wireless Mouse', $product->name);
        $this->assertSame('Ergonomic wireless mouse', $product->description);
        $this->assertSame(29.99, $product->price);
        $this->assertSame(150, $product->stock);
        $this->assertInstanceOf(DateTimeImmutable::class, $product->createdAt);
        $this->assertInstanceOf(DateTimeImmutable::class, $product->updatedAt);
    }

    public function testFromRowCastsTypes(): void
    {
        $row = $this->sampleRow();
        $row['id'] = '42';
        $row['price'] = '99.50';
        $row['stock'] = '0';

        $product = Product::fromRow($row);

        $this->assertSame(42, $product->id);
        $this->assertSame(99.50, $product->price);
        $this->assertSame(0, $product->stock);
    }

    public function testFromRowDefaultsDescriptionToEmpty(): void
    {
        $row = $this->sampleRow();
        unset($row['description']);

        $product = Product::fromRow($row);

        $this->assertSame('', $product->description);
    }

    public function testToArrayMatchesPdoFormat(): void
    {
        $product = Product::fromRow($this->sampleRow());
        $array = $product->toArray();

        $this->assertSame(1, $array['id']);
        $this->assertSame('Wireless Mouse', $array['name']);
        $this->assertSame('Ergonomic wireless mouse', $array['description']);
        // Price should be a string with 2 decimal places (matching PDO DECIMAL output)
        $this->assertSame('29.99', $array['price']);
        $this->assertSame(150, $array['stock']);
        $this->assertSame('2024-01-15 10:30:00', $array['created_at']);
        $this->assertSame('2024-01-15 10:30:00', $array['updated_at']);
    }

    public function testToArrayFormatsWholeNumberPrice(): void
    {
        $row = $this->sampleRow();
        $row['price'] = '100.00';

        $product = Product::fromRow($row);

        $this->assertSame('100.00', $product->toArray()['price']);
    }

    public function testConstructorDirectly(): void
    {
        $now = new DateTimeImmutable();
        $product = new Product(
            id: 5,
            name: 'Test',
            description: 'Desc',
            price: 10.50,
            stock: 3,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->assertSame(5, $product->id);
        $this->assertSame('Test', $product->name);
        $this->assertSame(10.50, $product->price);
    }
}
