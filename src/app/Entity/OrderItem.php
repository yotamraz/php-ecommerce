<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\ManyToOne(targetEntity: Product::class, inversedBy: 'orderItems')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Product $product;

    #[ORM\Column(name: 'quantity', type: 'integer')]
    private int $quantity = 1;

    #[ORM\Column(name: 'price', type: 'decimal', precision: 10, scale: 2)]
    private string $price = '0.00';

    public function getId(): ?int { return $this->id; }

    public function getOrder(): Order { return $this->order; }

    public function setOrder(Order $order): void { $this->order = $order; }

    public function getProduct(): Product { return $this->product; }

    public function setProduct(Product $product): void { $this->product = $product; }

    public function getQuantity(): int { return $this->quantity; }

    public function setQuantity(int $quantity): void { $this->quantity = $quantity; }

    public function getPrice(): string { return $this->price; }

    public function setPrice(string $price): void { $this->price = $price; }
}
