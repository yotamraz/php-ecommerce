<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use Predis\Client as RedisClient;
use Respect\Validation\Validator as v;
use Respect\Validation\Exceptions\NestedValidationException;

/**
 * Business logic for order operations.
 *
 * Owns the transaction boundary for order creation — coordinates repository
 * calls within a single DB transaction, then publishes an event AFTER commit.
 */
class OrderService
{
    public function __construct(
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
     * Get a single order by ID (with items).
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
     * Flow:
     * 1. Validate input (items array with product_id and quantity)
     * 2. Begin transaction
     * 3. Create order row
     * 4. For each item: lock product (FOR UPDATE), check stock, insert order_item, decrement stock
     * 5. Update order total
     * 6. Commit transaction
     * 7. Publish event (AFTER commit — no phantom events on rollback)
     * 8. Invalidate product caches
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> The created order with items
     */
    public function createOrder(array $data): array
    {
        $this->validateOrderData($data);

        $items = $data['items'];
        $total = 0.0;

        $this->orderRepository->beginTransaction();

        try {
            // Step 1: Create the order row
            $orderId = $this->orderRepository->createOrder();

            // Step 2: Process each item within the transaction
            $affectedProductIds = [];
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];

                // Lock the product row for update (pessimistic locking)
                $product = $this->orderRepository->findProductForUpdate($productId);
                if ($product === null) {
                    throw new \RuntimeException(
                        "Product not found: {$productId}",
                        404
                    );
                }

                // Check sufficient stock
                if ((int) $product['stock'] < $quantity) {
                    throw new \RuntimeException(
                        "Insufficient stock for product: {$product['name']}",
                        409
                    );
                }

                $itemPrice = (float) $product['price'];
                $lineTotal = $itemPrice * $quantity;

                // Insert order item
                $this->orderRepository->insertOrderItem(
                    $orderId,
                    $productId,
                    $quantity,
                    $itemPrice,
                );

                // Decrement stock
                $this->orderRepository->decrementProductStock($productId, $quantity);

                $total += $lineTotal;
                $affectedProductIds[] = $productId;
            }

            // Step 3: Update order total
            $this->orderRepository->updateOrderTotal($orderId, $total);

            // Step 4: Commit
            $this->orderRepository->commit();
        } catch (\Throwable $e) {
            $this->orderRepository->rollBack();
            throw $e;
        }

        // Step 5: Publish event AFTER successful commit
        try {
            $this->eventPublisher->publish('order_created', [
                'order_id' => $orderId,
                'total' => $total,
                'items_count' => count($items),
            ]);
        } catch (\Throwable) {
            // Queue publishing failure should not fail the order
            // The order is already committed — log and continue
        }

        // Step 6: Invalidate product caches for affected products
        foreach ($affectedProductIds as $productId) {
            $this->cache->del("products:{$productId}");
        }
        $this->cache->del('products:all');

        // Return the created order with items
        return $this->orderRepository->findById($orderId);
    }

    /**
     * Validate order input data using Respect/Validation.
     *
     * @throws \InvalidArgumentException On invalid input
     */
    private function validateOrderData(array $data): void
    {
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            throw new \InvalidArgumentException('items is required and must be a non-empty array');
        }

        foreach ($data['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("items[{$index}] must be an object");
            }

            try {
                v::key('product_id', v::intVal()->positive())
                    ->key('quantity', v::intVal()->positive())
                    ->assert($item);
            } catch (NestedValidationException $e) {
                $messages = $e->getMessages();
                $firstMessage = reset($messages);
                if (!isset($item['product_id'])) {
                    throw new \InvalidArgumentException("items[{$index}].product_id is required");
                }
                if (!isset($item['quantity'])) {
                    throw new \InvalidArgumentException("items[{$index}].quantity is required");
                }
                throw new \InvalidArgumentException("items[{$index}]: {$firstMessage}");
            }
        }
    }
}
