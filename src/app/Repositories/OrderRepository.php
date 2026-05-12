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
     * Fetch all orders (without items).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    /**
     * Fetch a single order by ID with its items.
     *
     * @return array<string, mixed>|null Order with 'items' key, or null if not found
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        return $order;
    }

    /**
     * Insert a new order row and return its ID.
     *
     * @return int The new order ID
     */
    public function createOrder(string $status = 'pending'): int
    {
        $stmt = $this->db->prepare('INSERT INTO orders (status, total) VALUES (?, 0)');
        $stmt->execute([$status]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert an order item row.
     */
    public function createOrderItem(int $orderId, int $productId, int $quantity, float $price): void
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

    /**
     * Get the PDO connection (for transaction management by the service layer).
     */
    public function getConnection(): PDO
    {
        return $this->db;
    }
}
