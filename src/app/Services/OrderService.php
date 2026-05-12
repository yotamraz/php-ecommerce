<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Services\EventPublisher;
use Predis\Client as RedisClient;
use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\NestedValidationException;

/**
 * Business logic for order operations.
 * Owns transaction boundaries for order creation with stock locking.
 */
class OrderService
{
    private const CACHE_KEY_PRODUCTS_ALL = 'products:all';

    public function __construct(
        private OrderRepository $orderRepository,
        private EventPublisher $eventPublisher,
        private RedisClient $cache,
    ) {}

    /**
     * List all orders with their items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOrders(): array
    {
        return $this->orderRepository->findAll();
    }

    /**
     * Get a single order by ID.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException If order not found (404)
     */
    public function getOrder(int $id): array
    {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            throw new \RuntimeException('Order not found', 404);
        }
        return $order;
    }

    /**
     * Create a new order with transactional stock management.
     *
     * The transaction flow:
     * 1. Begin transaction
     * 2. Insert order row
     * 3. For each item: lock product row (SELECT FOR UPDATE), verify stock, insert order_item, decrement stock
     * 4. Update order total
     * 5. Commit
     * 6. Publish event (AFTER commit — never inside transaction)
     *
     * @param array<string, mixed> $data Request data with 'items' array
     * @return array<string, mixed> The created order with items
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $db = $this->orderRepository->getConnection();
        $db->beginTransaction();

        try {
            // Insert order with zero total (updated after items are processed)
            $orderId = $this->orderRepository->insertOrder('pending', 0.00);
            $total = 0.00;

            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Lock product row for update (pessimistic locking)
                $product = $this->orderRepository->findProductForUpdate($productId);
                if ($product === null) {
                    throw new \RuntimeException("Product {$productId} not found", 404);
                }

                if ((int) $product['stock'] < $quantity) {
                    throw new \RuntimeException(
                        "Insufficient stock for product {$productId}. Available: {$product['stock']}, requested: {$quantity}",
                        409
                    );
                }

                $itemPrice = (float) $product['price'];
                $itemTotal = $itemPrice * $quantity;

                $this->orderRepository->insertOrderItem($orderId, $productId, $quantity, $itemPrice);
                $this->orderRepository->decrementProductStock($productId, $quantity);

                $total += $itemTotal;
            }

            // Update order with computed total
            $this->orderRepository->updateOrderTotal($orderId, $total);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Invalidate product caches since stock changed
        $this->invalidateProductCaches($data['items']);

        // Publish event AFTER successful commit
        try {
            $this->eventPublisher->publish('order_created', [
                'order_id' => $orderId,
                'total' => $total,
                'items' => $data['items'],
            ]);
        } catch (\Throwable $e) {
            // Log but don't fail the order — the order was committed successfully
            // In production, this should go to a proper logger
        }

        return $this->orderRepository->findById($orderId);
    }

    /**
     * Validate order input data using Respect/Validation.
     *
     * @throws NestedValidationException If validation fails
     */
    private function validateOrderData(array $data): void
    {
        // Validate top-level structure: items must be a non-empty array
        v::key('items', v::arrayType()->notEmpty()->setName('items'))
            ->assert($data);

        // Validate each item has product_id and a positive quantity
        $itemValidator = v::keySet(
            v::key('product_id', v::intVal()->positive()->setName('product_id')),
            v::key('quantity', v::intVal()->positive()->setName('quantity')),
        );

        foreach ($data['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("items[{$index}] must be an object");
            }
            try {
                $itemValidator->assert($item);
            } catch (NestedValidationException $e) {
                // Re-throw with item index context for better error messages
                throw new \InvalidArgumentException(
                    "items[{$index}]: " . implode('; ', $e->getMessages())
                );
            }
        }
    }

    /**
     * Invalidate product caches after stock changes.
     */
    private function invalidateProductCaches(array $items): void
    {
        // Invalidate the products list cache
        $this->cache->del(self::CACHE_KEY_PRODUCTS_ALL);

        // Invalidate individual product caches
        foreach ($items as $item) {
            $this->cache->del("products:{$item['product_id']}");
        }
    }
}
