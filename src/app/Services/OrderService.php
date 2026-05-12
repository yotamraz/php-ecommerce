<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use PDO;
use Predis\Client as RedisClient;

/**
 * Business logic for order operations.
 * Owns transaction boundaries for order creation — the repository executes
 * queries within this service's transaction scope.
 */
class OrderService
{
    public function __construct(
        private OrderRepository $orderRepository,
        private EventPublisher $eventPublisher,
        private PDO $db,
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
     * Get a single order with its items.
     *
     * @return array<string, mixed>|null
     */
    public function getOrder(int $id): ?array
    {
        return $this->orderRepository->findByIdWithItems($id);
    }

    /**
     * Create an order with transactional stock management.
     *
     * The transaction spans: order insert, stock checks (FOR UPDATE), order item
     * inserts, stock decrements, and total update. Event publishing happens AFTER
     * the commit to avoid phantom events on rollback.
     *
     * @param array<string, mixed> $data Request data with 'items' array
     * @return array<string, mixed> The created order with items
     * @throws \InvalidArgumentException On validation failure
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $this->db->beginTransaction();
        try {
            $orderId = $this->orderRepository->createOrder();

            $total = 0.0;
            $productIds = [];

            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Pessimistic lock on product row
                $product = $this->orderRepository->findProductForUpdate($productId);
                if ($product === null) {
                    throw new \InvalidArgumentException("Product {$productId} not found");
                }

                if ((int) $product['stock'] < $quantity) {
                    throw new \InvalidArgumentException("Insufficient stock for {$product['name']}");
                }

                $lineTotal = (float) $product['price'] * $quantity;

                $this->orderRepository->createOrderItem(
                    $orderId,
                    $productId,
                    $quantity,
                    (float) $product['price'],
                );

                $this->orderRepository->decrementStock($productId, $quantity);

                $productIds[] = $productId;
                $total += $lineTotal;
            }

            $this->orderRepository->updateOrderTotal($orderId, $total);

            $this->db->commit();
        } catch (\InvalidArgumentException $e) {
            $this->db->rollBack();
            throw $e;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw new \RuntimeException('Order creation failed', 500, $e);
        }

        // Invalidate product caches AFTER commit
        foreach ($productIds as $pid) {
            $this->cache->del("products:{$pid}");
        }
        $this->cache->del('products:all');

        // Publish event AFTER commit — never inside a transaction
        $this->eventPublisher->publish('order_created', [
            'order_id' => $orderId,
            'total' => $total,
            'created_at' => date('c'),
        ]);

        return $this->orderRepository->findByIdWithItems($orderId);
    }

    /**
     * Validate order creation input data.
     *
     * @throws ValidationException On validation failure
     */
    private function validateOrderData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            throw new ValidationException('items array is required', [
                'items' => 'items array is required and must not be empty',
            ]);
        }

        foreach ($data['items'] as $index => $item) {
            if (!isset($item['product_id'], $item['quantity'])) {
                throw new ValidationException('Each item needs product_id and quantity', [
                    "items[{$index}]" => 'product_id and quantity are required',
                ]);
            }

            if ((int) $item['quantity'] < 1) {
                throw new ValidationException('Quantity must be at least 1', [
                    "items[{$index}].quantity" => 'Quantity must be at least 1',
                ]);
            }
        }
    }
}
