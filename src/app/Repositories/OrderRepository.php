<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for orders and order items.
 * Uses raw PDO (will migrate to Doctrine DBAL in milestone 3).
 */
class OrderRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * Get the PDO connection (needed by service layer for transaction management).
     */
    public function getConnection(): PDO
    {
        return $this->db;
    }

    /**
     * Retrieve all orders with their items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        $orders = $stmt->fetchAll();

        foreach ($orders as &$order) {
            $order['items'] = $this->findItemsByOrderId((int) $order['id']);
        }

        return $orders;
    }

    /**
     * Retrieve a single order by ID with its items.
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

        $order['items'] = $this->findItemsByOrderId((int) $order['id']);
        return $order;
    }

    /**
     * Find order items by order ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findItemsByOrderId(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Insert a new order row and return its ID.
     *
     * @return int The new order ID
     */
    public function insertOrder(string $status = 'pending', float $total = 0.00): int
    {
        $stmt = $this->db->prepare('INSERT INTO orders (status, total) VALUES (?, ?)');
        $stmt->execute([$status, $total]);
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

    /**
     * Lock a product row for update and return it (used within a transaction).
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
     * Decrement product stock within a transaction.
     */
    public function decrementProductStock(int $productId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
        $stmt->execute([$quantity, $productId]);
    }

    /**
     * Check if any orders reference a given product.
     */
    public function existsByProductId(int $productId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM order_items WHERE product_id = ? LIMIT 1');
        $stmt->execute([$productId]);
        return (bool) $stmt->fetch();
    }
}
