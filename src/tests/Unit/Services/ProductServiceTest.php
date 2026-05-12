<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\ProductRepository;
use App\Services\ProductService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Respect\Validation\Exceptions\NestedValidationException;

class ProductServiceTest extends TestCase
{
    private ProductRepository&MockObject $repository;
    private MockObject $cache;
    private ProductService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProductRepository::class);
        $this->cache = $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['get', 'setex', 'del'])
            ->getMock();
        $this->service = new ProductService($this->repository, $this->cache);
    }

    public function testListProductsReturnsCachedData(): void
    {
        $cached = json_encode([['id' => 1, 'name' => 'Test']]);
        $this->cache->method('get')->with('products:all')->willReturn($cached);
        $this->repository->expects($this->never())->method('findAll');

        $result = $this->service->listProducts();

        $this->assertCount(1, $result);
        $this->assertEquals('Test', $result[0]['name']);
    }

    public function testListProductsQueriesDbOnCacheMiss(): void
    {
        $products = [['id' => 1, 'name' => 'Widget', 'price' => 9.99, 'stock' => 10]];
        $this->cache->method('get')->with('products:all')->willReturn(null);
        $this->repository->method('findAll')->willReturn($products);
        $this->cache->expects($this->once())->method('setex')
            ->with('products:all', 60, json_encode($products));

        $result = $this->service->listProducts();

        $this->assertEquals($products, $result);
    }

    public function testGetProductReturnsCachedData(): void
    {
        $cached = json_encode(['id' => 1, 'name' => 'Test']);
        $this->cache->method('get')->with('products:1')->willReturn($cached);
        $this->repository->expects($this->never())->method('findById');

        $result = $this->service->getProduct(1);

        $this->assertEquals(1, $result['id']);
    }

    public function testGetProductReturnsNullWhenNotFound(): void
    {
        $this->cache->method('get')->willReturn(null);
        $this->repository->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getProduct(999);

        $this->assertNull($result);
    }

    // --- Validation tests (Respect/Validation) ---

    public function testCreateProductMissingNameThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct(['price' => 10.00]);
    }

    public function testCreateProductMissingPriceThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct(['name' => 'Test']);
    }

    public function testCreateProductEmptyBodyThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct([]);
    }

    public function testCreateProductZeroPriceThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct(['name' => 'Test', 'price' => 0]);
    }

    public function testCreateProductNegativePriceThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct(['name' => 'Test', 'price' => -5]);
    }

    public function testCreateProductNegativeStockThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct(['name' => 'Test', 'price' => 10, 'stock' => -1]);
    }

    public function testCreateProductEmptyNameThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createProduct(['name' => '', 'price' => 10]);
    }

    public function testCreateProductSuccess(): void
    {
        $data = ['name' => 'New Product', 'price' => 25.99, 'stock' => 10];
        $dbRow = ['id' => 5, 'name' => 'New Product', 'description' => '', 'price' => 25.99, 'stock' => 10, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01'];

        $this->repository->method('create')->willReturn(5);
        $this->repository->method('findById')->with(5)->willReturn($dbRow);
        $this->cache->expects($this->once())->method('del')->with('products:all');

        $result = $this->service->createProduct($data);

        $this->assertEquals(5, $result['id']);
        $this->assertEquals('New Product', $result['name']);
    }

    public function testCreateProductWithOptionalDescription(): void
    {
        $data = ['name' => 'With Desc', 'price' => 15.00, 'description' => 'A description'];
        $dbRow = ['id' => 6, 'name' => 'With Desc', 'description' => 'A description', 'price' => 15.00, 'stock' => 0, 'created_at' => '2024-01-01', 'updated_at' => '2024-01-01'];

        $this->repository->method('create')->willReturn(6);
        $this->repository->method('findById')->with(6)->willReturn($dbRow);

        $result = $this->service->createProduct($data);

        $this->assertEquals('A description', $result['description']);
    }

    // --- Update tests ---

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

        $this->service->updateProduct(1, []);
    }

    public function testUpdateProductNegativePriceThrowsValidation(): void
    {
        $this->repository->method('exists')->with(1)->willReturn(true);

        $this->expectException(NestedValidationException::class);

        $this->service->updateProduct(1, ['price' => -10]);
    }

    public function testUpdateProductNegativeStockThrowsValidation(): void
    {
        $this->repository->method('exists')->with(1)->willReturn(true);

        $this->expectException(NestedValidationException::class);

        $this->service->updateProduct(1, ['stock' => -5]);
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

    // --- Delete tests ---

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
        $pdoException->errorInfo = ['23000', 1451, 'FK constraint'];
        // PDOException stores the SQLSTATE code as a string in the $code property
        // We use reflection to set it since the constructor only accepts int
        $ref = new \ReflectionProperty(\Exception::class, 'code');
        $ref->setAccessible(true);
        $ref->setValue($pdoException, '23000');

        $this->repository->method('delete')->willThrowException($pdoException);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete product that is referenced by orders');

        $this->service->deleteProduct(1);
    }

    public function testDeleteProductSuccess(): void
    {
        $this->repository->method('delete')->with(1)->willReturn(true);

        $this->service->deleteProduct(1);

        $this->assertTrue(true);
    }
}
