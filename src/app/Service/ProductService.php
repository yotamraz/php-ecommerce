<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Predis\Client as RedisClient;

class ProductService
{
    private const int CACHE_TTL = 60;

    public function __construct(
        private readonly ProductRepository $repository,
        private readonly RedisClient $cache,
    ) {}

    /**
     * List all products (cached for 60s under key "products:all").
     *
     * @return Product[]
     */
    public function listAll(): array
    {
        $cached = $this->cache->get('products:all');
        if ($cached !== null) {
            $rows = json_decode($cached, true);
            return array_map(Product::fromRow(...), $rows);
        }

        $products = $this->repository->findAll();

        $this->cache->setex(
            'products:all',
            self::CACHE_TTL,
            json_encode(array_map(fn(Product $p) => $p->toArray(), $products)),
        );

        return $products;
    }

    /**
     * Get a single product by ID (cached for 60s under key "products:{id}").
     */
    public function getById(int $id): ?Product
    {
        $cached = $this->cache->get("products:{$id}");
        if ($cached !== null) {
            $row = json_decode($cached, true);
            return Product::fromRow($row);
        }

        $product = $this->repository->findById($id);
        if ($product === null) {
            return null;
        }

        $this->cache->setex(
            "products:{$id}",
            self::CACHE_TTL,
            json_encode($product->toArray()),
        );

        return $product;
    }

    /**
     * Create a new product and invalidate the list cache.
     */
    public function create(string $name, string $description, float $price, int $stock): Product
    {
        $id = $this->repository->create($name, $description, $price, $stock);
        $this->cache->del('products:all');

        // Fetch the newly created product to return a fully hydrated entity
        return $this->getById($id);
    }

    /**
     * Update product fields and invalidate relevant caches.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): ?Product
    {
        if (!$this->repository->exists($id)) {
            return null;
        }

        $this->repository->update($id, $fields);

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        return $this->getById($id);
    }

    /**
     * Delete a product and invalidate relevant caches.
     *
     * @return int Number of rows deleted (0 = not found)
     * @throws \PDOException If a FK constraint prevents deletion
     */
    public function delete(int $id): int
    {
        $rowCount = $this->repository->delete($id);

        if ($rowCount > 0) {
            $this->cache->del("products:{$id}");
            $this->cache->del('products:all');
        }

        return $rowCount;
    }
}
