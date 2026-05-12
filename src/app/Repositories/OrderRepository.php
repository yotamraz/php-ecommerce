<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for orders and order items.
 * Uses raw PDO (will migrate to Doctrine DBAL in milestone 3).
 *
 * Transaction management is owned by the service layer — this repository
 * executes queries within the transaction scope managed by OrderService.
 */
class OrderRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * List all orders, newest first.
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
     * Find an order by ID with its items (including product names).
     *
     * @return array<string, mixed>|null
     */
    public function findByIdWithItems(int $id): ?array
    {
        $order = $this->findById($id);
        if ($order === null) {
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
     * Create a new order with initial total of 0.
     *
     * @return int The ID of the newly created order
     */
    public function createOrder(): int
    {
        $this->db->prepare('INSERT INTO orders (total) VALUES (0)')->execute();
        return (int) $this->db->lastInsertId();
    }

    /**
     * Add an item to an order.
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
     * Fetch a product row with a pessimistic lock (SELECT ... FOR UPDATE).
     * Must be called within a transaction.
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
     * Decrement product stock. Must be called within a transaction.
     */
    public function decrementStock(int $productId, int $quantity): void
    {
        $stmt = $this->db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
        $stmt->execute([$quantity, $productId]);
    }
}
