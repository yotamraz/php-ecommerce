<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use Predis\Client as RedisClient;
use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\NestedValidationException;

/**
 * Business logic for order operations.
 * Owns the transaction boundary for order creation, validates input via Respect/Validation,
 * and publishes events after successful commits.
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
     * Get a single order by ID (with items).
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
     * The transaction boundary is managed here in the service layer because
     * it spans multiple repository operations that must be atomic:
     * 1. Create the order record
     * 2. For each item: lock the product row, verify stock, create order item, decrement stock
     * 3. Update the order total
     *
     * Event publishing happens AFTER the transaction commits to avoid phantom events.
     *
     * @param array<string, mixed> $data Request payload with 'items' array
     * @return array<string, mixed> The created order with items
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $this->orderRepository->beginTransaction();

        try {
            // Create the order record
            $orderId = $this->orderRepository->createOrder('pending', 0);
            $total = 0.0;

            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Lock the product row for update (pessimistic locking)
                $product = $this->orderRepository->findProductForUpdate($productId);
                if ($product === null) {
                    throw new \RuntimeException("Product {$productId} not found", 404);
                }

                // Check stock availability
                if ((int) $product['stock'] < $quantity) {
                    throw new \InvalidArgumentException(
                        "Insufficient stock for product {$product['name']}. Available: {$product['stock']}, requested: {$quantity}"
                    );
                }

                $itemPrice = (float) $product['price'];
                $lineTotal = $itemPrice * $quantity;

                // Create order item and decrement stock
                $this->orderRepository->createOrderItem($orderId, $productId, $quantity, $itemPrice);
                $this->orderRepository->decrementStock($productId, $quantity);

                $total += $lineTotal;
            }

            // Update order total
            $this->orderRepository->updateOrderTotal($orderId, $total);

            $this->orderRepository->commit();
        } catch (\Throwable $e) {
            $this->orderRepository->rollBack();
            throw $e;
        }

        // Invalidate product caches since stock changed
        $this->invalidateProductCaches($data['items']);

        // Publish event AFTER successful commit (never inside transaction)
        $this->eventPublisher->publish('order_created', [
            'order_id' => $orderId,
            'total' => $total,
            'items' => $data['items'],
        ]);

        return $this->orderRepository->findById($orderId);
    }

    /**
     * Validate order creation input using Respect/Validation.
     *
     * @param array<string, mixed> $data
     */
    private function validateOrderData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('items is required and must be an array');
        }

        if (empty($data['items'])) {
            throw new \InvalidArgumentException('items cannot be empty');
        }

        foreach ($data['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Item at index {$index} must be an object");
            }

            if (!isset($item['product_id'])) {
                throw new \InvalidArgumentException("product_id is required for item at index {$index}");
            }

            if (!isset($item['quantity'])) {
                throw new \InvalidArgumentException("quantity is required for item at index {$index}");
            }

            try {
                v::intVal()->positive()->assert($item['product_id']);
            } catch (NestedValidationException $e) {
                throw new \InvalidArgumentException("product_id must be a positive integer for item at index {$index}");
            }

            try {
                v::intVal()->positive()->assert($item['quantity']);
            } catch (NestedValidationException $e) {
                throw new \InvalidArgumentException("quantity must be a positive integer for item at index {$index}");
            }
        }
    }

    /**
     * Invalidate product caches after order creation (stock changed).
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function invalidateProductCaches(array $items): void
    {
        $this->cache->del('products:all');
        foreach ($items as $item) {
            $this->cache->del("products:{$item['product_id']}");
        }
    }
}
