<?php

namespace App\Repository;

use App\Entity\Order;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Order>
 */
class OrderRepository extends EntityRepository
{
    /**
     * Fetches an Order entity with its items collection eagerly initialized.
     * Replaces the JOIN query: SELECT oi.*, p.name as product_name FROM order_items oi
     *                          JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?
     *
     * @return Order|null
     */
    public function findWithItems(int $id): ?Order
    {
        return $this->createQueryBuilder('o')
            ->leftJoin('o.items', 'i')
            ->leftJoin('i.product', 'p')
            ->addSelect('i', 'p')
            ->where('o.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
