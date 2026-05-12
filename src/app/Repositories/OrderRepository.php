<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for orders and order items.
 * Uses raw PDO (will migrate to Doctrine DBAL in milestone 3).
 *
 * Transaction boundaries are owned by the service layer — this repository
 * provides individual operations that the service calls within a transaction.
 */
class OrderRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    /**
     * Find an order by ID with its items (including product names).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        $stmt = $this->db->prepare(
            'SELECT oi.*, p.name as product_name FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        return $order;
    }

    /**
     * Begin a database transaction.
     */
    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    /**
     * Commit the current transaction.
     */
    public function commit(): void
    {
        $this->db->commit();
    }

    /**
     * Roll back the current transaction.
     */
    public function rollBack(): void
    {
        $this->db->rollBack();
    }

    /**
     * Create a new order row with total = 0.
     *
     * @return int The newly created order ID
     */
    public function createOrder(): int
    {
        $this->db->prepare('INSERT INTO orders (total) VALUES (0)')->execute();
        return (int) $this->db->lastInsertId();
    }

    /**
     * Get a product with a FOR UPDATE lock for stock checking.
     *
     * @return array<string, mixed>|null
     */
    public function findProductForUpdate(int $productId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        return $product ?: null;
    }

    /**
     * Insert an order item row.
     */
    public function insertOrderItem(int $orderId, int $productId, int $quantity, float $price): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $productId, $quantity, $price]);
    }

    /**
     * Decrement product stock by the given quantity.
     */
    public function decrementStock(int $productId, int $quantity): void
    {
        $this->db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')
            ->execute([$quantity, $productId]);
    }

    /**
     * Update the order total.
     */
    public function updateOrderTotal(int $orderId, float $total): void
    {
        $this->db->prepare('UPDATE orders SET total = ? WHERE id = ?')
            ->execute([$total, $orderId]);
    }
}
