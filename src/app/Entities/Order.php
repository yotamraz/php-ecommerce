<?php

declare(strict_types=1);

namespace App\Entities;

/**
 * Order entity using PHP 8.4 property hooks and asymmetric visibility.
 */
class Order
{
    public private(set) int $id;
    public private(set) string $status;
    public private(set) string $createdAt;
    public private(set) string $updatedAt;

    /** Total with validation hook — cannot be negative */
    public private(set) float $total {
        set(float $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('total cannot be negative');
            }
            $this->total = $value;
        }
    }

    /** @var array<int, OrderItem> */
    public private(set) array $items;

    public function __construct(
        int $id,
        string $status,
        float $total,
        string $createdAt = '',
        string $updatedAt = '',
        array $items = [],
    ) {
        $this->id = $id;
        $this->status = $status;
        $this->total = $total;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->items = $items;
    }

    /**
     * Create an Order from a database row (associative array).
     *
     * @param array<string, mixed> $row
     * @param array<int, array<string, mixed>> $itemRows
     */
    public static function fromRow(array $row, array $itemRows = []): self
    {
        $items = array_map(
            fn(array $itemRow) => OrderItem::fromRow($itemRow),
            $itemRows,
        );

        return new self(
            id: (int) $row['id'],
            status: (string) ($row['status'] ?? 'pending'),
            total: (float) $row['total'],
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            items: $items,
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
            'status' => $this->status,
            'total' => $this->total,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'items' => array_map(fn(OrderItem $item) => $item->toArray(), $this->items),
        ];
    }
}
