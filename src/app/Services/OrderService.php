<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use Predis\Client as RedisClient;
use PDO;

/**
 * Business logic for order operations.
 * Owns transaction boundaries for order creation with stock locking.
 */
class OrderService
{
    private const CACHE_KEY_ALL_PRODUCTS = 'products:all';

    public function __construct(
        private PDO $db,
        private OrderRepository $orderRepository,
        private ProductRepository $productRepository,
        private RedisClient $cache,
        private EventPublisher $eventPublisher,
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
     * Get a single order by ID with its items.
     *
     * @return array<string, mixed>|null
     */
    public function getOrder(int $id): ?array
    {
        return $this->orderRepository->findById($id);
    }

    /**
     * Create an order with transactional stock management.
     *
     * The transaction flow:
     * 1. BEGIN TRANSACTION
     * 2. Insert order row (status=pending, total=0)
     * 3. For each item: lock product row (SELECT...FOR UPDATE), validate stock, insert order_item, decrement stock
     * 4. Update order total
     * 5. COMMIT
     * 6. Publish order_created event (AFTER commit — no phantom events on rollback)
     * 7. Invalidate caches
     *
     * @param array<string, mixed> $data Request data with 'items' array
     * @return array<string, mixed> The created order with items
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $this->db->beginTransaction();

        try {
            // Create the order row
            $orderId = $this->orderRepository->createOrder('pending');
            $total = 0.0;

            // Process each item
            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Lock the product row
                $product = $this->productRepository->findByIdForUpdate($productId);
                if ($product === null) {
                    throw new \RuntimeException("Product {$productId} not found", 404);
                }

                // Check stock
                if ((int) $product['stock'] < $quantity) {
                    throw new \RuntimeException(
                        "Insufficient stock for product {$productId}",
                        400
                    );
                }

                $itemPrice = (float) $product['price'];
                $itemTotal = $itemPrice * $quantity;

                // Insert order item
                $this->orderRepository->createOrderItem($orderId, $productId, $quantity, $itemPrice);

                // Decrement stock
                $this->productRepository->decrementStock($productId, $quantity);

                $total += $itemTotal;
            }

            // Update order total
            $this->orderRepository->updateOrderTotal($orderId, $total);

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        // Publish event AFTER commit (no phantom events)
        try {
            $this->eventPublisher->publish('order_created', [
                'order_id' => $orderId,
                'total' => $total,
            ]);
        } catch (\Throwable $e) {
            // Log failure but don't fail the order — event publishing is best-effort
            // In production, this would be logged; for now we silently continue
        }

        // Invalidate product caches (stock changed)
        $this->invalidateProductCaches($data['items']);

        // Return the created order
        return $this->orderRepository->findById($orderId);
    }

    /**
     * Validate order input data.
     */
    private function validateOrderData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            throw new \InvalidArgumentException('Order must contain items');
        }

        foreach ($data['items'] as $index => $item) {
            if (!isset($item['product_id'])) {
                throw new \InvalidArgumentException("Item {$index}: product_id is required");
            }
            if (!isset($item['quantity']) || (int) $item['quantity'] <= 0) {
                throw new \InvalidArgumentException("Item {$index}: quantity must be greater than zero");
            }
        }
    }

    /**
     * Invalidate product caches after stock changes.
     */
    private function invalidateProductCaches(array $items): void
    {
        // Invalidate the all-products list cache
        $this->cache->del(self::CACHE_KEY_ALL_PRODUCTS);

        // Invalidate individual product caches
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $this->cache->del("products:{$productId}");
        }
    }
}
