<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use PDO;
use PDOException;

class ProductRepository
{
    public function __construct(
        private readonly PDO $db,
    ) {}

    /**
     * @return Product[]
     */
    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT * FROM products ORDER BY id');
        $rows = $stmt->fetchAll();

        return array_map(Product::fromRow(...), $rows);
    }

    public function findById(int $id): ?Product
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? Product::fromRow($row) : null;
    }

    /**
     * Check whether a product with the given ID exists.
     */
    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$id]);

        return (bool) $stmt->fetch();
    }

    /**
     * Insert a new product and return its ID.
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
     * Update specific fields of a product.
     *
     * @param array<string, mixed> $fields Associative array of column => value
     * @return bool True if the product was found and updated
     */
    public function update(int $id, array $fields): bool
    {
        $setClauses = [];
        $values = [];

        foreach (['name', 'description', 'price', 'stock'] as $column) {
            if (array_key_exists($column, $fields)) {
                $setClauses[] = "{$column} = ?";
                $values[] = $fields[$column];
            }
        }

        if (empty($setClauses)) {
            return false;
        }

        $values[] = $id;
        $sql = 'UPDATE products SET ' . implode(', ', $setClauses) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($values);

        return true;
    }

    /**
     * Delete a product by ID.
     *
     * @return int Number of rows deleted (0 = not found)
     * @throws PDOException Re-thrown if a foreign key constraint prevents deletion
     */
    public function delete(int $id): int
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount();
    }
}
