<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for orders and order items.
 * Uses raw PDO (will migrate to Doctrine DBAL in milestone 3).
 *
 * Transaction management is exposed via getPdo() so the service layer
 * can control transaction boundaries — the service knows which operations
 * must be atomic.
 */
class OrderRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * Expose the PDO connection so the service layer can manage transactions.
     */
    public function getPdo(): PDO
    {
        return $this->db;
    }

    /**
     * Find all orders (without items).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        return $stmt->fetchAll();
    }

    /**
     * Find an order by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find order items for a given order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findItemsByOrderId(int $orderId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    /**
     * Insert a new order row and return its ID.
     */
    public function createOrder(string $status = 'pending', float $total = 0.00): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders (status, total) VALUES (?, ?)'
        );
        $stmt->execute([$status, $total]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Insert an order item row.
     */
    public function createOrderItem(int $orderId, int $productId, int $quantity, float $price): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$orderId, $productId, $quantity, $price]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the order total.
     */
    public function updateTotal(int $orderId, float $total): void
    {
        $stmt = $this->db->prepare('UPDATE orders SET total = ? WHERE id = ?');
        $stmt->execute([$total, $orderId]);
    }

    /**
     * Lock a product row for update and return it (used within a transaction).
     * Uses SELECT ... FOR UPDATE for pessimistic stock locking.
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
     * Decrement product stock (used within a transaction after locking).
     */
    public function decrementProductStock(int $productId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
        $stmt->execute([$quantity, $productId]);
    }

    /**
     * Check if any order items reference a given product.
     */
    public function productHasOrders(int $productId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM order_items WHERE product_id = ?');
        $stmt->execute([$productId]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
