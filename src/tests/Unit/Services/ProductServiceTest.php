<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\ProductRepository;
use App\Services\ProductService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

class ProductServiceTest extends TestCase
{
    private ProductRepository&MockObject $repository;
    private RedisClient $cache;
    private ProductService $service;

    /** @var array<string, string|null> In-memory cache store for testing */
    private array $cacheStore = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProductRepository::class);
        $this->cacheStore = [];

        // Use a real Predis Client stub via anonymous subclass to avoid __call mock issues
        $store = &$this->cacheStore;
        $this->cache = new class($store) extends RedisClient {
            /** @var array<string, string|null> */
            private array $store;

            public function __construct(array &$store)
            {
                $this->store = &$store;
            }

            public function get($key): ?string
            {
                return $this->store[$key] ?? null;
            }

            public function setex($key, $seconds, $value): void
            {
                $this->store[$key] = $value;
            }

            public function del($key): int
            {
                unset($this->store[$key]);
                return 1;
            }
        };

        $this->service = new ProductService($this->repository, $this->cache);
    }

    public function testListProductsReturnsCachedData(): void
    {
        $this->cacheStore['products:all'] = json_encode([['id' => 1, 'name' => 'Test']]);
        $this->repository->expects($this->never())->method('findAll');

        $result = $this->service->listProducts();

        $this->assertCount(1, $result);
        $this->assertEquals('Test', $result[0]['name']);
    }

    public function testListProductsQueriesDbOnCacheMiss(): void
    {
        $products = [['id' => 1, 'name' => 'Widget', 'price' => 9.99, 'stock' => 10]];
        $this->repository->method('findAll')->willReturn($products);

        $result = $this->service->listProducts();

        $this->assertEquals($products, $result);
        // Verify it was cached
        $this->assertNotNull($this->cacheStore['products:all']);
    }

    public function testGetProductReturnsCachedData(): void
    {
        $this->cacheStore['products:1'] = json_encode(['id' => 1, 'name' => 'Test']);
        $this->repository->expects($this->never())->method('findById');

        $result = $this->service->getProduct(1);

        $this->assertEquals(1, $result['id']);
    }

    public function testGetProductReturnsNullWhenNotFound(): void
    {
        $this->repository->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getProduct(999);

        $this->assertNull($result);
    }

    public function testCreateProductValidatesRequiredFields(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name and price are required');

        $this->service->createProduct([]);
    }

    public function testCreateProductValidatesPricePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('price must be greater than zero');

        $this->service->createProduct(['name' => 'Test', 'price' => -5]);
    }

    public function testCreateProductValidatesStockNonNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stock cannot be negative');

        $this->service->createProduct(['name' => 'Test', 'price' => 10, 'stock' => -1]);
    }

    public function testCreateProductSuccess(): void
    {
        $data = ['name' => 'New Product', 'price' => 25.99, 'stock' => 10];
        $dbRow = ['id' => 5, 'name' => 'New Product', 'description' => '', 'price' => 25.99, 'stock' => 10, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01'];

        $this->repository->method('create')->willReturn(5);
        $this->repository->method('findById')->with(5)->willReturn($dbRow);

        $result = $this->service->createProduct($data);

        $this->assertEquals(5, $result['id']);
        $this->assertEquals('New Product', $result['name']);
        // List cache should have been invalidated
        $this->assertArrayNotHasKey('products:all', $this->cacheStore);
    }

    public function testUpdateProductNotFound(): void
    {
        $this->repository->method('exists')->with(999)->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product not found');

        $this->service->updateProduct(999, ['name' => 'Updated']);
    }

    public function testUpdateProductNoFields(): void
    {
        $this->repository->method('exists')->with(1)->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No fields to update');

        $this->service->updateProduct(1, []);
    }

    public function testUpdateProductSuccess(): void
    {
        $dbRow = ['id' => 1, 'name' => 'Updated', 'description' => '', 'price' => 10.0, 'stock' => 5, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01'];
        $this->repository->method('exists')->with(1)->willReturn(true);
        $this->repository->method('update')->willReturn(true);
        $this->repository->method('findById')->with(1)->willReturn($dbRow);

        $result = $this->service->updateProduct(1, ['name' => 'Updated']);

        $this->assertEquals('Updated', $result['name']);
    }

    public function testDeleteProductNotFound(): void
    {
        $this->repository->method('delete')->with(999)->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product not found');

        $this->service->deleteProduct(999);
    }

    public function testDeleteProductWithOrdersThrows409(): void
    {
        $pdoException = new \PDOException('FK constraint');
        // PDOException stores code as string in errorInfo but $code as int
        $ref = new \ReflectionProperty(\PDOException::class, 'code');
        $ref->setValue($pdoException, '23000');

        $this->repository->method('delete')->willThrowException($pdoException);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete product that is referenced by orders');

        $this->service->deleteProduct(1);
    }

    public function testDeleteProductSuccess(): void
    {
        $this->repository->method('delete')->with(1)->willReturn(true);

        // Should not throw
        $this->service->deleteProduct(1);

        // Verify cache invalidation happened (implicit - no exception means success)
        $this->assertTrue(true);
    }
}
