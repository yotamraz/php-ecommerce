<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\OrderRepository;
use App\Services\EventPublisher;
use App\Services\OrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

class OrderServiceTest extends TestCase
{
    private OrderRepository&MockObject $repository;
    private EventPublisher&MockObject $eventPublisher;
    private RedisClient&MockObject $cache;
    private OrderService $service;
    private \PDO&MockObject $pdo;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrderRepository::class);
        $this->eventPublisher = $this->createMock(EventPublisher::class);
        $this->cache = $this->createMock(RedisClient::class);

        // Mock PDO for transaction management
        $this->pdo = $this->createMock(\PDO::class);
        $this->repository->method('getPdo')->willReturn($this->pdo);

        $this->service = new OrderService(
            $this->repository,
            $this->eventPublisher,
            $this->cache,
        );
    }

    // --- Validation tests ---

    public function testCreateOrderMissingItemsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items are required');

        $this->service->createOrder([]);
    }

    public function testCreateOrderItemsNotArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items must be a non-empty array');

        $this->service->createOrder(['items' => 'not-an-array']);
    }

    public function testCreateOrderEmptyItemsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items must be a non-empty array');

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderMissingProductIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('item 0: product_id is required');

        $this->service->createOrder(['items' => [
            ['quantity' => 2],
        ]]);
    }

    public function testCreateOrderMissingQuantityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('item 0: quantity is required');

        $this->service->createOrder(['items' => [
            ['product_id' => 1],
        ]]);
    }

    public function testCreateOrderZeroQuantityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('item 0: quantity must be greater than zero');

        $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => 0],
        ]]);
    }

    public function testCreateOrderNegativeQuantityThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('item 0: quantity must be greater than zero');

        $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => -3],
        ]]);
    }

    // --- Business logic tests ---

    public function testCreateOrderProductNotFoundThrows(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);

        $this->repository->method('createOrder')->willReturn(1);
        $this->repository->method('findProductForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [
            ['product_id' => 999, 'quantity' => 1],
        ]]);
    }

    public function testCreateOrderInsufficientStockThrows(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);

        $this->repository->method('createOrder')->willReturn(1);
        $this->repository->method('findProductForUpdate')->with(1)->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => 10.00, 'stock' => 2,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for product 1');

        $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => 5],
        ]]);
    }

    public function testCreateOrderSuccess(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('commit')->willReturn(true);
        $this->pdo->expects($this->never())->method('rollBack');

        $this->repository->method('createOrder')->willReturn(10);
        $this->repository->method('findProductForUpdate')
            ->willReturnCallback(fn(int $id) => match ($id) {
                1 => ['id' => 1, 'name' => 'Widget', 'price' => 29.99, 'stock' => 50],
                2 => ['id' => 2, 'name' => 'Gadget', 'price' => 49.99, 'stock' => 30],
                default => null,
            });

        $this->repository->expects($this->exactly(2))->method('createOrderItem');
        $this->repository->expects($this->exactly(2))->method('decrementProductStock');
        $this->repository->expects($this->once())->method('updateTotal')
            ->with(10, $this->callback(fn($total) => abs($total - (29.99 * 2 + 49.99 * 1)) < 0.01));

        // After commit, getOrder is called
        $this->repository->method('findById')->with(10)->willReturn([
            'id' => 10, 'status' => 'pending', 'total' => 109.97,
            'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
        ]);
        $this->repository->method('findItemsByOrderId')->with(10)->willReturn([
            ['id' => 1, 'order_id' => 10, 'product_id' => 1, 'quantity' => 2, 'price' => 29.99],
            ['id' => 2, 'order_id' => 10, 'product_id' => 2, 'quantity' => 1, 'price' => 49.99],
        ]);

        $result = $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => 2],
            ['product_id' => 2, 'quantity' => 1],
        ]]);

        $this->assertEquals(10, $result['id']);
        $this->assertEquals('pending', $result['status']);
        $this->assertCount(2, $result['items']);
    }

    public function testCreateOrderPublishesEvent(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        $this->repository->method('createOrder')->willReturn(5);
        $this->repository->method('findProductForUpdate')->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => 20.00, 'stock' => 100,
        ]);
        $this->repository->method('findById')->willReturn([
            'id' => 5, 'status' => 'pending', 'total' => 60.00,
            'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
        ]);
        $this->repository->method('findItemsByOrderId')->willReturn([
            ['id' => 1, 'order_id' => 5, 'product_id' => 1, 'quantity' => 3, 'price' => 20.00],
        ]);

        $this->eventPublisher->expects($this->once())->method('publish')
            ->with('order_created', $this->callback(function (array $data) {
                return $data['order_id'] === 5 && abs($data['total'] - 60.00) < 0.01;
            }));

        $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => 3],
        ]]);
    }

    public function testCreateOrderContinuesIfPublishFails(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        $this->repository->method('createOrder')->willReturn(5);
        $this->repository->method('findProductForUpdate')->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => 10.00, 'stock' => 100,
        ]);
        $this->repository->method('findById')->willReturn([
            'id' => 5, 'status' => 'pending', 'total' => 10.00,
            'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
        ]);
        $this->repository->method('findItemsByOrderId')->willReturn([
            ['id' => 1, 'order_id' => 5, 'product_id' => 1, 'quantity' => 1, 'price' => 10.00],
        ]);

        // EventPublisher throws — should not propagate
        $this->eventPublisher->method('publish')
            ->willThrowException(new \RuntimeException('RabbitMQ down'));

        // Should NOT throw — order is already committed
        $result = $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => 1],
        ]]);

        $this->assertEquals(5, $result['id']);
    }

    public function testCreateOrderInvalidatesCaches(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        $this->repository->method('createOrder')->willReturn(5);
        $this->repository->method('findProductForUpdate')->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => 10.00, 'stock' => 100,
        ]);
        $this->repository->method('findById')->willReturn([
            'id' => 5, 'status' => 'pending', 'total' => 10.00,
            'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
        ]);
        $this->repository->method('findItemsByOrderId')->willReturn([]);

        // Expect cache invalidation: products:all + products:1
        $this->cache->expects($this->exactly(2))->method('del')
            ->with($this->logicalOr('products:all', 'products:1'));

        $this->service->createOrder(['items' => [
            ['product_id' => 1, 'quantity' => 1],
        ]]);
    }

    public function testCreateOrderRollsBackOnFailure(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdo->expects($this->never())->method('commit');

        $this->repository->method('createOrder')->willReturn(1);
        $this->repository->method('findProductForUpdate')->willReturn(null);

        try {
            $this->service->createOrder(['items' => [
                ['product_id' => 999, 'quantity' => 1],
            ]]);
        } catch (\RuntimeException) {
            // Expected
        }
    }

    // --- Read operation tests ---

    public function testListOrders(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'pending', 'total' => 59.98],
            ['id' => 2, 'status' => 'processing', 'total' => 89.99],
        ];
        $this->repository->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
    }

    public function testGetOrderWithItems(): void
    {
        $this->repository->method('findById')->with(1)->willReturn([
            'id' => 1, 'status' => 'pending', 'total' => 59.98,
            'created_at' => '2024-01-01', 'updated_at' => '2024-01-01',
        ]);
        $this->repository->method('findItemsByOrderId')->with(1)->willReturn([
            ['id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => 29.99],
        ]);

        $result = $this->service->getOrder(1);

        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['items']);
    }

    public function testGetOrderNotFound(): void
    {
        $this->repository->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }
}
