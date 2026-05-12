<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use Predis\Client as RedisClient;

/**
 * Business logic for order operations.
 * Owns transaction boundaries for order creation.
 * Publishes events via EventPublisher AFTER transaction commit.
 */
class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private EventPublisher $eventPublisher,
        private RedisClient $cache,
    ) {}

    /**
     * List all orders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listOrders(): array
    {
        return $this->orderRepository->findAll();
    }

    /**
     * Get a single order by ID with items.
     *
     * @return array<string, mixed>|null
     */
    public function getOrder(int $id): ?array
    {
        return $this->orderRepository->findById($id);
    }

    /**
     * Create a new order with transactional stock management.
     *
     * Validates input, creates the order within a transaction (with FOR UPDATE
     * stock locking), invalidates product caches, and publishes an order_created
     * event AFTER the transaction commits.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> The created order with items
     * @throws \InvalidArgumentException On validation failure
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $this->orderRepository->beginTransaction();
        try {
            $orderId = $this->orderRepository->createOrder();

            $total = 0.0;
            $productIds = [];

            foreach ($data['items'] as $item) {
                $this->validateOrderItem($item);

                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Get product with FOR UPDATE lock
                $product = $this->orderRepository->findProductForUpdate($productId);
                if (!$product) {
                    throw new \InvalidArgumentException("Product {$productId} not found");
                }

                if ($product['stock'] < $quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for {$product['name']}");
                }

                // Add order item
                $lineTotal = (float) $product['price'] * $quantity;
                $this->orderRepository->insertOrderItem(
                    $orderId,
                    $productId,
                    $quantity,
                    (float) $product['price'],
                );

                // Decrement stock
                $this->orderRepository->decrementStock($productId, $quantity);

                $productIds[] = $productId;
                $total += $lineTotal;
            }

            // Update order total
            $this->orderRepository->updateOrderTotal($orderId, $total);

            $this->orderRepository->commit();
        } catch (\InvalidArgumentException $e) {
            $this->orderRepository->rollBack();
            throw $e;
        } catch (\Exception $e) {
            $this->orderRepository->rollBack();
            throw new \RuntimeException('Order creation failed', 500, $e);
        }

        // Invalidate product caches AFTER commit
        foreach ($productIds as $pid) {
            $this->cache->del("products:{$pid}");
        }
        $this->cache->del('products:all');

        // Publish event AFTER commit — never inside the transaction
        $this->eventPublisher->publish('order_created', [
            'order_id' => $orderId,
            'total' => $total,
            'created_at' => date('c'),
        ]);

        return $this->orderRepository->findById($orderId);
    }

    /**
     * Validate the top-level order data structure.
     *
     * @throws \InvalidArgumentException
     */
    private function validateOrderData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            throw new \InvalidArgumentException('items array is required');
        }
    }

    /**
     * Validate a single order item.
     *
     * @throws \InvalidArgumentException
     */
    private function validateOrderItem(array $item): void
    {
        if (!isset($item['product_id'], $item['quantity'])) {
            throw new \InvalidArgumentException('Each item needs product_id and quantity');
        }

        if ((int) $item['quantity'] < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1');
        }
    }
}
