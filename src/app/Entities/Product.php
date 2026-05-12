<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * Product entity using PHP 8.2 readonly properties.
 */
class Product
{
    public readonly int $id;
    public readonly string $name;
    public readonly string $description;
    public readonly string $createdAt;
    public readonly string $updatedAt;
    public readonly float $price;
    public readonly int $stock;

    public function __construct(
        int $id,
        string $name,
        string $description,
        float $price,
        int $stock,
        string $createdAt = '',
        string $updatedAt = '',
    ) {
        if ($price <= 0) {
            throw new \InvalidArgumentException('price must be greater than zero');
        }
        if ($stock < 0) {
            throw new \InvalidArgumentException('stock cannot be negative');
        }

        $this->id = $id;
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->stock = $stock;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    /**
     * Create a Product from a database row (associative array).
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: (string) ($row['description'] ?? ''),
            price: (float) $row['price'],
            stock: (int) $row['stock'],
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
        );
    }

    /**
     * Convert to associative array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'stock' => $this->stock,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
