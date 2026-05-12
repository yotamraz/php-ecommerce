<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for orders and order items.
 * Uses raw PDO with transaction support (will migrate to Doctrine DBAL in milestone 3).
 */
class OrderRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    // ── Transaction helpers ──────────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollBack(): void
    {
        $this->db->rollBack();
    }

    // ── Read operations ──────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    /**
     * Fetch an order with its items.
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

        $itemStmt = $this->db->prepare(
            'SELECT oi.*, p.name AS product_name
             FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $itemStmt->execute([$id]);
        $order['items'] = $itemStmt->fetchAll();

        return $order;
    }

    // ── Write operations ─────────────────────────────────────────────

    /**
     * Insert a new order row (initially with total = 0).
     *
     * @return int The new order ID
     */
    public function createOrder(string $status = 'pending'): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders (status, total) VALUES (?, 0)'
        );
        $stmt->execute([$status]);
        return (int) $this->db->lastInsertId();
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
     * Update the order total.
     */
    public function updateOrderTotal(int $orderId, float $total): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET total = ? WHERE id = ?');
        $stmt->execute([$total, $orderId]);
    }

    // ── Stock operations (used within order transaction) ─────────────

    /**
     * Lock a product row for update and return its data.
     * Must be called within an active transaction.
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
     * Decrement product stock. Must be called within an active transaction.
     */
    public function decrementProductStock(int $productId, int $quantity): void
    {
        $stmt = $this->db->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ?'
        );
        $stmt->execute([$quantity, $productId]);
    }
}
