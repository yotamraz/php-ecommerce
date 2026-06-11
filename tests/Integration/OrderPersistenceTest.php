<?php

namespace App\Tests\Integration;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Repository\OrderRepository;
use App\Tests\AbstractDoctrineTestCase;

class OrderPersistenceTest extends AbstractDoctrineTestCase
{
    private function createProduct(string $name, string $price, int $stock): Product
    {
        $p = new Product();
        $p->setName($name);
        $p->setPrice($price);
        $p->setStock($stock);
        $this->em->persist($p);
        return $p;
    }

    public function testCreateOrderWithItems(): void
    {
        $product = $this->createProduct('Widget', '9.99', 50);
        $this->em->flush();
        $productId = $product->getId();

        $order = new Order();
        $this->em->persist($order);
        $this->em->flush();

        $item = new OrderItem();
        $item->setOrder($order);
        $item->setProduct($product);
        $item->setQuantity(3);
        $item->setPrice($product->getPrice());
        $this->em->persist($item);

        $order->setTotal(number_format((float)$product->getPrice() * 3, 2, '.', ''));
        $product->setStock($product->getStock() - 3);
        $this->em->flush();

        $orderId = $order->getId();
        $this->em->clear();

        // Verify order
        $foundOrder = $this->em->find(Order::class, $orderId);
        $this->assertNotNull($foundOrder);
        $this->assertSame('pending', $foundOrder->getStatus());
        $this->assertSame('29.97', $foundOrder->getTotal());

        // Verify product stock was decremented
        $foundProduct = $this->em->find(Product::class, $productId);
        $this->assertSame(47, $foundProduct->getStock());
    }

    public function testFindWithItemsEagerLoad(): void
    {
        $product = $this->createProduct('Gadget', '15.00', 10);
        $this->em->flush();

        $order = new Order();
        $this->em->persist($order);
        $this->em->flush();

        $item = new OrderItem();
        $item->setOrder($order);
        $item->setProduct($product);
        $item->setQuantity(2);
        $item->setPrice('15.00');
        $this->em->persist($item);
        $order->setTotal('30.00');
        $this->em->flush();

        $orderId = $order->getId();
        $this->em->clear();

        /** @var OrderRepository $repo */
        $repo = $this->em->getRepository(Order::class);
        $foundOrder = $repo->findWithItems($orderId);

        $this->assertNotNull($foundOrder);
        $items = $foundOrder->getItems()->toArray();
        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]->getQuantity());
        $this->assertSame('Gadget', $items[0]->getProduct()->getName());
    }

    public function testOrderCascadeRemovesItems(): void
    {
        $product = $this->createProduct('Cascade Test', '5.00', 20);
        $this->em->flush();

        $order = new Order();
        $this->em->persist($order);
        $this->em->flush();

        $item = new OrderItem();
        $item->setOrder($order);
        $item->setProduct($product);
        $item->setQuantity(1);
        $item->setPrice('5.00');
        $this->em->persist($item);
        $order->setTotal('5.00');
        $this->em->flush();

        $itemId = $item->getId();
        $this->em->clear();

        // Remove order — items should cascade-delete
        $foundOrder = $this->em->find(Order::class, $order->getId());
        $this->em->remove($foundOrder);
        $this->em->flush();
        $this->em->clear();

        $this->assertNull($this->em->find(OrderItem::class, $itemId));
    }

    public function testDeleteProductWithOrderItemsThrowsOrRestricts(): void
    {
        $product = $this->createProduct('Referenced Product', '20.00', 5);
        $this->em->flush();

        $order = new Order();
        $this->em->persist($order);
        $this->em->flush();

        $item = new OrderItem();
        $item->setOrder($order);
        $item->setProduct($product);
        $item->setQuantity(1);
        $item->setPrice('20.00');
        $this->em->persist($item);
        $order->setTotal('20.00');
        $this->em->flush();
        $this->em->clear();

        $foundProduct = $this->em->find(Product::class, $product->getId());
        $this->em->remove($foundProduct);

        // PRAGMA foreign_keys=ON is enabled in AbstractDoctrineTestCase::setUp(),
        // so SQLite will enforce the ON DELETE RESTRICT constraint on product_id.
        $this->expectException(\Throwable::class);
        $this->em->flush();
    }
}
