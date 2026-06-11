<?php

namespace App\Entity;

use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'status', type: 'string', length: 20)]
    private string $status = 'pending';

    #[ORM\Column(name: 'total', type: 'decimal', precision: 10, scale: 2)]
    private string $total = '0.00';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist', 'remove'])]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->items     = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getStatus(): string { return $this->status; }

    public function setStatus(string $status): void { $this->status = $status; }

    public function getTotal(): string { return $this->total; }

    public function setTotal(string $total): void { $this->total = $total; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function getItems(): Collection { return $this->items; }

    public function addItem(OrderItem $item): void { $this->items->add($item); }
}
