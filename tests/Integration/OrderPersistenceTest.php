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

    /**
     * Tests the explicit beginTransaction / flush / commit / clear / findWithItems
     * sequence that mirrors the createOrder() handler in Router.php.
     * Validates that after commit+clear, findWithItems returns the order with
     * correctly populated items (not stale identity-map data) — Risk #2 mitigation.
     */
    public function testCreateOrderTransaction(): void
    {
        $product = $this->createProduct('Transactional Widget', '15.00', 10);
        $this->em->flush();
        $productId = $product->getId();

        $this->em->beginTransaction();
        try {
            $order = new Order();
            $this->em->persist($order);
            $this->em->flush();

            $qty = 3;
            $lineTotal = (float) $product->getPrice() * $qty;

            $item = new OrderItem();
            $item->setOrder($order);
            $item->setProduct($product);
            $item->setQuantity($qty);
            $item->setPrice($product->getPrice());
            $this->em->persist($item);

            $product->setStock($product->getStock() - $qty);
            $order->setTotal(number_format($lineTotal, 2, '.', ''));
            $this->em->flush();
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollBack();
            throw $e;
        }

        $orderId = $order->getId();

        // Clear identity map — next reads must come from DB, not cache
        $this->em->clear();

        /** @var OrderRepository $repo */
        $repo = $this->em->getRepository(Order::class);
        $found = $repo->findWithItems($orderId);

        $this->assertNotNull($found, 'Order must be retrievable after commit+clear');
        // Compare as float: SQLite may strip trailing zeros ('45' vs '45.00')
        $this->assertEqualsWithDelta(45.00, (float) $found->getTotal(), 0.001);

        $items = $found->getItems()->toArray();
        $this->assertCount(1, $items, 'Order must have exactly one item after commit+clear');
        $this->assertSame(3, $items[0]->getQuantity());
        // Compare as float: SQLite may strip trailing zeros ('15' vs '15.00')
        $this->assertEqualsWithDelta(15.00, (float) $items[0]->getPrice(), 0.001);
        $this->assertSame('Transactional Widget', $items[0]->getProduct()->getName());

        // Stock decrement must also be persisted
        $freshProduct = $this->em->find(Product::class, $productId);
        $this->assertSame(7, $freshProduct->getStock());
    }

    /**
     * Tests that a rollBack() on insufficient stock leaves the order unpersisted.
     */
    public function testCreateOrderRollbackOnInsufficientStock(): void
    {
        $product = $this->createProduct('Low-Stock Item', '25.00', 2);
        $this->em->flush();

        $this->em->beginTransaction();
        $exceptionCaught = false;
        $orderId = null;
        try {
            $order = new Order();
            $this->em->persist($order);
            $this->em->flush();
            $orderId = $order->getId();

            // Request more stock than available
            $requestedQty = 5;
            if ($product->getStock() < $requestedQty) {
                throw new \InvalidArgumentException('Insufficient stock');
            }
        } catch (\InvalidArgumentException $e) {
            $this->em->rollBack();
            $exceptionCaught = true;
        }

        $this->assertTrue($exceptionCaught, 'Stock check exception must be caught');

        // After rollback, clear the identity map to bypass any cached state
        $this->em->clear();

        // The order must not exist in the database after rollback
        $this->assertNull(
            $this->em->find(Order::class, $orderId),
            'Order must not be persisted after transaction rollback'
        );

        // Product stock must be unchanged
        $freshProduct = $this->em->find(Product::class, $product->getId());
        $this->assertSame(2, $freshProduct->getStock(), 'Stock must not be decremented after rollback');
    }
}
