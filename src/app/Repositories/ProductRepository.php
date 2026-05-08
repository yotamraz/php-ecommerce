<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Data access layer for products. Uses raw PDO (will migrate to Doctrine DBAL in milestone 3).
 */
class ProductRepository
{
    public function __construct(
        private PDO $db,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM products ORDER BY id');
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return int The ID of the newly created product
     */
    public function create(string $name, string $description, float $price, int $stock): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $description, $price, $stock]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields Key-value pairs of fields to update
     * @return bool True if a row was updated
     */
    public function update(int $id, array $fields): bool
    {
        $setClauses = [];
        $values = [];
        foreach ($fields as $column => $value) {
            $setClauses[] = "{$column} = ?";
            $values[] = $value;
        }
        $values[] = $id;

        $sql = 'UPDATE products SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($values);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return bool True if a row was deleted
     * @throws \PDOException If product is referenced by orders (FK constraint)
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Check if a product exists.
     */
    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetch();
    }
}
