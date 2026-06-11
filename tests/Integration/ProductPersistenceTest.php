<?php

namespace App\Tests\Integration;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Tests\AbstractDoctrineTestCase;

class ProductPersistenceTest extends AbstractDoctrineTestCase
{
    public function testPersistAndRetrieveProduct(): void
    {
        $product = new Product();
        $product->setName('Test Widget');
        $product->setDescription('A test product');
        $product->setPrice('19.99');
        $product->setStock(100);

        $this->em->persist($product);
        $this->em->flush();
        $id = $product->getId();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);

        $this->em->clear();

        $found = $this->em->find(Product::class, $id);
        $this->assertInstanceOf(Product::class, $found);
        $this->assertSame('Test Widget', $found->getName());
        $this->assertSame('19.99', $found->getPrice());
        $this->assertSame(100, $found->getStock());
    }

    public function testFindAllOrdered(): void
    {
        foreach (['Banana', 'Apple', 'Cherry'] as $i => $name) {
            $p = new Product();
            $p->setName($name);
            $p->setPrice('9.99');
            $p->setStock($i + 1);
            $this->em->persist($p);
        }
        $this->em->flush();
        $this->em->clear();

        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $products = $repo->findAllOrdered();

        $this->assertCount(3, $products);
        $ids = array_map(fn($p) => $p->getId(), $products);
        $sortedIds = $ids;
        sort($sortedIds);
        $this->assertSame($sortedIds, $ids, 'Products should be ordered by id ASC');
    }

    public function testUpdateProduct(): void
    {
        $product = new Product();
        $product->setName('Old Name');
        $product->setPrice('10.00');
        $product->setStock(5);
        $this->em->persist($product);
        $this->em->flush();
        $id = $product->getId();

        $product->setName('New Name');
        $product->setStock(10);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(Product::class, $id);
        $this->assertSame('New Name', $found->getName());
        $this->assertSame(10, $found->getStock());
    }

    public function testDecimalPricePreservedAsString(): void
    {
        $product = new Product();
        $product->setName('Precision Test');
        $product->setPrice('29.99');
        $product->setStock(1);
        $this->em->persist($product);
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->find(Product::class, $product->getId());
        // Price must come back as string '29.99', not float
        $this->assertIsString($found->getPrice());
        $this->assertSame('29.99', $found->getPrice());
        // JSON encoding must not introduce float imprecision
        $json = json_encode(['price' => $found->getPrice()]);
        $this->assertStringContainsString('"price":"29.99"', $json);
    }
}
