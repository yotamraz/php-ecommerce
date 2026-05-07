<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;

readonly class Product
{
    public function __construct(
        public int $id,
        public string $name,
        public string $description,
        public float $price,
        public int $stock,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Hydrate a Product from a database row (associative array).
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: $row['name'],
            description: $row['description'] ?? '',
            price: (float) $row['price'],
            stock: (int) $row['stock'],
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }

    /**
     * Serialize to an array matching the original PDO FETCH_ASSOC format.
     *
     * MySQL DECIMAL columns are returned as strings by PDO, so price is
     * formatted as a string with 2 decimal places to preserve backward
     * compatibility with the original API response format.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => number_format($this->price, 2, '.', ''),
            'stock' => $this->stock,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }
}
