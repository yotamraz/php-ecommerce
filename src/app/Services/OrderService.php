<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use Predis\Client as RedisClient;
use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\NestedValidationException;

/**
 * Business logic for order operations.
 * Owns transaction boundaries for order creation, validates input,
 * and publishes events after successful commits.
 */
class OrderService
{
    public function __construct(
        private OrderRepository $repository,
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
        return $this->repository->findAll();
    }

    /**
     * Get a single order by ID, including its items.
     *
     * @return array<string, mixed>|null
     */
    public function getOrder(int $id): ?array
    {
        $order = $this->repository->findById($id);
        if ($order === null) {
            return null;
        }

        $order['items'] = $this->repository->findItemsByOrderId($id);
        return $order;
    }

    /**
     * Create an order transactionally.
     *
     * Validates input, locks product rows (SELECT ... FOR UPDATE), checks stock,
     * inserts order + order_items, decrements stock, and publishes an event
     * AFTER the transaction commits.
     *
     * @param array<string, mixed> $data Request body with 'items' array
     * @return array<string, mixed> The created order with items
     */
    public function createOrder(array $data): array
    {
        $this->validateCreateData($data);

        $pdo = $this->repository->getPdo();
        $pdo->beginTransaction();

        try {
            // Insert the order shell
            $orderId = $this->repository->createOrder('pending', 0.00);

            $total = 0.00;
            $orderItems = [];

            foreach ($data['items'] as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Lock the product row and check stock
                $product = $this->repository->findProductForUpdate($productId);
                if ($product === null) {
                    throw new \RuntimeException(
                        "Product {$productId} not found",
                        400,
                    );
                }

                if ((int) $product['stock'] < $quantity) {
                    throw new \RuntimeException(
                        "Insufficient stock for product {$productId}",
                        409,
                    );
                }

                // Calculate line total at current product price
                $linePrice = (float) $product['price'];
                $lineTotal = $linePrice * $quantity;
                $total += $lineTotal;

                // Insert order item and decrement stock
                $this->repository->createOrderItem($orderId, $productId, $quantity, $linePrice);
                $this->repository->decrementProductStock($productId, $quantity);

                $orderItems[] = [
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'price' => $linePrice,
                ];
            }

            // Update order total
            $this->repository->updateTotal($orderId, $total);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Invalidate product caches for affected products (stock changed)
        $this->invalidateProductCaches($data['items']);

        // Publish event AFTER commit — never inside the transaction
        try {
            $this->eventPublisher->publish('order_created', [
                'order_id' => $orderId,
                'total' => $total,
                'items' => $orderItems,
            ]);
        } catch (\Throwable) {
            // Queue publish failure should not fail the order creation
            // The order is already committed
        }

        return $this->getOrder($orderId);
    }

    /**
     * Validate order creation input data using Respect/Validation.
     *
     * @param array<string, mixed> $data
     */
    private function validateCreateData(array $data): void
    {
        if (!isset($data['items'])) {
            throw new \InvalidArgumentException('items are required');
        }

        if (!is_array($data['items']) || empty($data['items'])) {
            throw new \InvalidArgumentException('items must be a non-empty array');
        }

        foreach ($data['items'] as $index => $item) {
            if (!isset($item['product_id'])) {
                throw new \InvalidArgumentException("item {$index}: product_id is required");
            }

            if (!isset($item['quantity'])) {
                throw new \InvalidArgumentException("item {$index}: quantity is required");
            }

            // Validate quantity using Respect/Validation
            try {
                v::intVal()->positive()->assert($item['quantity']);
            } catch (NestedValidationException) {
                throw new \InvalidArgumentException("item {$index}: quantity must be greater than zero");
            }
        }
    }

    /**
     * Invalidate product caches after stock changes from order creation.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function invalidateProductCaches(array $items): void
    {
        // Invalidate product list cache
        $this->cache->del('products:all');

        // Invalidate individual product caches
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $this->cache->del("products:{$productId}");
        }
    }
}
