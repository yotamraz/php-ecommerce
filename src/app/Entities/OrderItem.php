<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * OrderItem entity using PHP 8.4 property hooks and asymmetric visibility.
 */
class OrderItem
{
    public private(set) int $id;
    public private(set) int $orderId;
    public private(set) int $productId;
    public private(set) string $productName;

    /** Quantity with validation hook — must be at least 1 */
    public private(set) int $quantity {
        set(int $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Quantity must be at least 1');
            }
            $this->quantity = $value;
        }
    }

    /** Price with validation hook — must be positive */
    public private(set) float $price {
        set(float $value) {
            if ($value <= 0) {
                throw new \InvalidArgumentException('price must be greater than zero');
            }
            $this->price = $value;
        }
    }

    public function __construct(
        int $id,
        int $orderId,
        int $productId,
        int $quantity,
        float $price,
        string $productName = '',
    ) {
        $this->id = $id;
        $this->orderId = $orderId;
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->price = $price;
        $this->productName = $productName;
    }

    /**
     * Create an OrderItem from a database row (associative array).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            orderId: (int) $row['order_id'],
            productId: (int) $row['product_id'],
            quantity: (int) $row['quantity'],
            price: (float) $row['price'],
            productName: (string) ($row['product_name'] ?? ''),
        );
    }

    /**
     * Convert to associative array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'product_name' => $this->productName,
        ];
    }
}
