<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use Predis\Client as RedisClient;

/**
 * Business logic for order operations.
 *
 * Owns the transaction boundary for order creation, which spans:
 * order insert → stock lock/check → item inserts → stock decrement → total update.
 *
 * Event publishing happens AFTER commit to avoid phantom events on rollback.
 */
class OrderService
{
    private const CACHE_KEY_ALL_PRODUCTS = 'products:all';

    public function __construct(
        private OrderRepository $orderRepository,
        private ProductRepository $productRepository,
        private RedisClient $cache,
        private EventPublisher $eventPublisher,
    ) {}

    /**
     * List all orders, most recent first.
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
     * Create an order with stock validation, transactional writes, cache invalidation,
     * and event publishing.
     *
     * @param array<string, mixed> $data Request payload with 'items' array
     * @return array<string, mixed> The created order (with items)
     *
     * @throws \InvalidArgumentException On validation failure
     * @throws \RuntimeException On unexpected errors
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $this->orderRepository->beginTransaction();
        try {
            $orderId = $this->orderRepository->createOrder();

            $total = 0.0;
            $affectedProductIds = [];

            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Lock the product row and check stock
                $product = $this->orderRepository->findProductForUpdate($productId);
                if ($product === null) {
                    throw new \InvalidArgumentException("Product {$productId} not found");
                }

                if ((int) $product['stock'] < $quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for {$product['name']}");
                }

                // Insert order item at the product's current price
                $lineTotal = (float) $product['price'] * $quantity;
                $this->orderRepository->insertOrderItem(
                    $orderId,
                    $productId,
                    $quantity,
                    (float) $product['price'],
                );

                // Decrement stock
                $this->orderRepository->decrementProductStock($productId, $quantity);

                $affectedProductIds[] = $productId;
                $total += $lineTotal;
            }

            // Update the order total
            $this->orderRepository->updateOrderTotal($orderId, $total);

            $this->orderRepository->commit();
        } catch (\InvalidArgumentException $e) {
            $this->orderRepository->rollBack();
            throw $e;
        } catch (\Exception $e) {
            $this->orderRepository->rollBack();
            throw new \RuntimeException('Order creation failed', 500, $e);
        }

        // ── Post-commit side effects (outside transaction) ───────

        // Invalidate product caches for affected products
        foreach ($affectedProductIds as $pid) {
            $this->cache->del("products:{$pid}");
        }
        $this->cache->del(self::CACHE_KEY_ALL_PRODUCTS);

        // Publish order_created event to RabbitMQ
        $this->eventPublisher->publish('order_created', [
            'order_id' => $orderId,
            'total' => $total,
            'created_at' => date('c'),
        ]);

        return $this->orderRepository->findById($orderId);
    }

    /**
     * Validate the incoming order data.
     *
     * @throws \InvalidArgumentException On invalid input
     */
    private function validateOrderData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            throw new \InvalidArgumentException('items array is required');
        }

        foreach ($data['items'] as $index => $item) {
            if (!isset($item['product_id'], $item['quantity'])) {
                throw new \InvalidArgumentException('Each item needs product_id and quantity');
            }

            if ((int) $item['quantity'] < 1) {
                throw new \InvalidArgumentException('Quantity must be at least 1');
            }
        }
    }
}
