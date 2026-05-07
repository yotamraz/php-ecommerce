<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\ProductService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

class ProductServiceTest extends TestCase
{
    private ProductRepository&MockObject $repository;
    private RedisClient&MockObject $cache;
    private ProductService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProductRepository::class);
        $this->cache = $this->createMock(RedisClient::class);
        $this->service = new ProductService($this->repository, $this->cache);
    }

    private function makeProduct(int $id = 1, string $name = 'Test Product'): Product
    {
        return new Product(
            id: $id,
            name: $name,
            description: 'A test product',
            price: 29.99,
            stock: 10,
            createdAt: new DateTimeImmutable('2024-01-01 00:00:00'),
            updatedAt: new DateTimeImmutable('2024-01-01 00:00:00'),
        );
    }

    // --- listAll ---

    public function testListAllReturnsCachedData(): void
    {
        $cachedJson = json_encode([
            [
                'id' => 1,
                'name' => 'Cached Product',
                'description' => 'desc',
                'price' => '9.99',
                'stock' => 5,
                'created_at' => '2024-01-01 00:00:00',
                'updated_at' => '2024-01-01 00:00:00',
            ],
        ]);

        $this->cache->expects($this->once())
            ->method('__call')
            ->with('get', ['products:all'])
            ->willReturn($cachedJson);

        $this->repository->expects($this->never())->method('findAll');

        $products = $this->service->listAll();

        $this->assertCount(1, $products);
        $this->assertSame('Cached Product', $products[0]->name);
    }

    public function testListAllQueriesRepoOnCacheMiss(): void
    {
        $product = $this->makeProduct();

        $this->cache->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function (string $method, array $args) {
                if ($method === 'get') {
                    return null;
                }
                // setex call
                return null;
            });

        $this->repository->expects($this->once())
            ->method('findAll')
            ->willReturn([$product]);

        $products = $this->service->listAll();

        $this->assertCount(1, $products);
        $this->assertSame('Test Product', $products[0]->name);
    }

    // --- getById ---

    public function testGetByIdReturnsCachedProduct(): void
    {
        $cachedJson = json_encode([
            'id' => 1,
            'name' => 'Cached',
            'description' => 'desc',
            'price' => '19.99',
            'stock' => 3,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);

        $this->cache->expects($this->once())
            ->method('__call')
            ->with('get', ['products:1'])
            ->willReturn($cachedJson);

        $this->repository->expects($this->never())->method('findById');

        $product = $this->service->getById(1);

        $this->assertNotNull($product);
        $this->assertSame('Cached', $product->name);
    }

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        $this->cache->expects($this->once())
            ->method('__call')
            ->with('get', ['products:99'])
            ->willReturn(null);

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);

        $result = $this->service->getById(99);

        $this->assertNull($result);
    }

    // --- create ---

    public function testCreateInvalidatesCacheAndReturnsProduct(): void
    {
        $product = $this->makeProduct(id: 5);

        $this->repository->expects($this->once())
            ->method('create')
            ->with('New', 'Desc', 19.99, 10)
            ->willReturn(5);

        // del('products:all'), get('products:5'), setex(...)
        $this->cache->expects($this->atLeast(2))
            ->method('__call')
            ->willReturnCallback(function (string $method, array $args) {
                if ($method === 'get' && $args[0] === 'products:5') {
                    return null; // cache miss for newly created product
                }
                return null;
            });

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(5)
            ->willReturn($product);

        $result = $this->service->create('New', 'Desc', 19.99, 10);

        $this->assertSame(5, $result->id);
    }

    // --- delete ---

    public function testDeleteInvalidatesCacheOnSuccess(): void
    {
        $this->repository->expects($this->once())
            ->method('delete')
            ->with(1)
            ->willReturn(1);

        // Expects del calls for products:1 and products:all
        $this->cache->expects($this->exactly(2))
            ->method('__call')
            ->willReturnCallback(function (string $method, array $args) {
                $this->assertSame('del', $method);
                return 1;
            });

        $result = $this->service->delete(1);

        $this->assertSame(1, $result);
    }

    public function testDeleteSkipsCacheOnNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('delete')
            ->with(99)
            ->willReturn(0);

        $this->cache->expects($this->never())->method('__call');

        $result = $this->service->delete(99);

        $this->assertSame(0, $result);
    }

    // --- update ---

    public function testUpdateReturnsNullWhenProductNotFound(): void
    {
        $this->repository->expects($this->once())
            ->method('exists')
            ->with(99)
            ->willReturn(false);

        $this->repository->expects($this->never())->method('update');

        $result = $this->service->update(99, ['name' => 'New Name']);

        $this->assertNull($result);
    }
}
