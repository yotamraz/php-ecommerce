<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ValidationException;
use App\Repositories\ProductRepository;
use Predis\Client as RedisClient;

/**
 * Business logic for product operations.
 * Handles validation, caching, and delegates to repository for data access.
 */
class ProductService
{
    private const CACHE_TTL = 60; // seconds
    private const CACHE_KEY_ALL = 'products:all';

    public function __construct(
        private ProductRepository $repository,
        private RedisClient $cache,
    ) {}

    /**
     * List all products (cached).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listProducts(): array
    {
        $cached = $this->cache->get(self::CACHE_KEY_ALL);
        if ($cached) {
            return json_decode($cached, true);
        }

        $products = $this->repository->findAll();
        $this->cache->setex(self::CACHE_KEY_ALL, self::CACHE_TTL, json_encode($products));
        return $products;
    }

    /**
     * Get a single product by ID (cached).
     *
     * @return array<string, mixed>|null
     */
    public function getProduct(int $id): ?array
    {
        $cacheKey = "products:{$id}";
        $cached = $this->cache->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }

        $product = $this->repository->findById($id);
        if ($product !== null) {
            $this->cache->setex($cacheKey, self::CACHE_TTL, json_encode($product));
        }
        return $product;
    }

    /**
     * Create a new product.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> The created product
     */
    public function createProduct(array $data): array
    {
        $this->validateCreateData($data);

        $id = $this->repository->create(
            name: $data['name'],
            description: $data['description'] ?? '',
            price: (float) $data['price'],
            stock: (int) ($data['stock'] ?? 0),
        );

        $this->invalidateListCache();

        return $this->getProductOrFail($id);
    }

    /**
     * Update an existing product.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed> The updated product
     */
    public function updateProduct(int $id, array $data): array
    {
        if (!$this->repository->exists($id)) {
            throw new \RuntimeException('Product not found', 404);
        }

        $fields = $this->extractUpdateFields($data);
        if (empty($fields)) {
            throw new \InvalidArgumentException('No fields to update');
        }

        $this->repository->update($id, $fields);
        $this->invalidateProductCache($id);
        $this->invalidateListCache();

        return $this->getProductOrFail($id);
    }

    /**
     * Delete a product.
     *
     * @throws \RuntimeException If product not found or referenced by orders
     */
    public function deleteProduct(int $id): void
    {
        try {
            $deleted = $this->repository->delete($id);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new \RuntimeException('Cannot delete product that is referenced by orders', 409);
            }
            throw $e;
        }

        if (!$deleted) {
            throw new \RuntimeException('Product not found', 404);
        }

        $this->invalidateProductCache($id);
        $this->invalidateListCache();
    }

    /**
     * Validate input data for product creation.
     *
     * Uses manual checks that match original error messages for backward
     * compatibility. The ValidationException carries structured details
     * when multiple fields fail.
     */
    private function validateCreateData(array $data): void
    {
        // Check required fields exist
        if (!isset($data['name'], $data['price'])) {
            throw new ValidationException('name and price are required', [
                'name' => !isset($data['name']) ? 'name is required' : null,
                'price' => !isset($data['price']) ? 'price is required' : null,
            ]);
        }

        // Validate price is positive
        if ((float) $data['price'] <= 0) {
            throw new ValidationException('price must be greater than zero', [
                'price' => 'price must be greater than zero',
            ]);
        }

        // Validate stock if provided
        if (isset($data['stock']) && (int) $data['stock'] < 0) {
            throw new ValidationException('stock cannot be negative', [
                'stock' => 'stock cannot be negative',
            ]);
        }
    }

    /**
     * Extract allowed update fields from request data.
     * Uses array_find to check for valid fields (PHP 8.4).
     *
     * @return array<string, mixed>
     */
    private function extractUpdateFields(array $data): array
    {
        $allowedFields = ['name', 'description', 'price', 'stock'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
            }
        }

        return $fields;
    }

    private function invalidateProductCache(int $id): void
    {
        $this->cache->del("products:{$id}");
    }

    private function invalidateListCache(): void
    {
        $this->cache->del(self::CACHE_KEY_ALL);
    }

    /**
     * Get a product by ID or throw 404.
     *
     * @return array<string, mixed>
     */
    private function getProductOrFail(int $id): array
    {
        $product = $this->repository->findById($id);
        if ($product === null) {
            throw new \RuntimeException('Product not found', 404);
        }
        return $product;
    }
}
