<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for orders and order items. Uses raw PDO (will migrate to Doctrine DBAL in milestone 3).
 */
class OrderRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * List all orders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    /**
     * Find an order by ID including its items with product names.
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
            'SELECT oi.*, p.name as product_name
             FROM order_items oi
             JOIN products p ON oi.product_id = p.id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        return $order;
    }

    /**
     * Create a new order record.
     *
     * @return int The new order ID
     */
    public function createOrder(string $status = 'pending', float $total = 0): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders (status, total) VALUES (?, ?)'
        );
        $stmt->execute([$status, $total]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert an order item.
     */
    public function createOrderItem(int $orderId, int $productId, int $quantity, float $price): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $productId, $quantity, $price]);
    }

    /**
     * Lock a product row for update (pessimistic locking within a transaction).
     *
     * @return array<string, mixed>|null
     */
    public function findProductForUpdate(int $productId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Decrement product stock by a given quantity.
     */
    public function decrementStock(int $productId, int $quantity): void
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ?'
        );
        $stmt->execute([$quantity, $productId]);
    }

    /**
     * Update the order total.
     */
    public function updateOrderTotal(int $orderId, float $total): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET total = ? WHERE id = ?');
        $stmt->execute([$total, $orderId]);
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
}
