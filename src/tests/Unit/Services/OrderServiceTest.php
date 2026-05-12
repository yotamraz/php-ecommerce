<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ValidationException;
use App\Repositories\OrderRepository;
use App\Services\EventPublisher;
use App\Services\OrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

class OrderServiceTest extends TestCase
{
    private OrderRepository&MockObject $orderRepository;
    private EventPublisher&MockObject $eventPublisher;
    private \PDO&MockObject $db;
    private RedisClient&MockObject $cache;
    private OrderService $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->eventPublisher = $this->createMock(EventPublisher::class);
        $this->db = $this->createMock(\PDO::class);
        $this->cache = $this->createMock(RedisClient::class);

        $this->service = new OrderService(
            $this->orderRepository,
            $this->eventPublisher,
            $this->db,
            $this->cache,
        );
    }

    public function testListOrdersDelegatesToRepository(): void
    {
        $orders = [
            ['id' => 2, 'status' => 'pending', 'total' => '50.00'],
            ['id' => 1, 'status' => 'pending', 'total' => '25.00'],
        ];
        $this->orderRepository->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertEquals($orders, $result);
    }

    public function testGetOrderReturnsOrderWithItems(): void
    {
        $order = [
            'id' => 1,
            'status' => 'pending',
            'total' => '29.99',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => '29.99', 'product_name' => 'Widget'],
            ],
        ];
        $this->orderRepository->method('findByIdWithItems')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertEquals($order, $result);
        $this->assertArrayHasKey('items', $result);
    }

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepository->method('findByIdWithItems')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }

    public function testCreateOrderRequiresItemsArray(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder([]);
    }

    public function testCreateOrderRejectsEmptyItems(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderRejectsNonArrayItems(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder(['items' => 'not-an-array']);
    }

    public function testCreateOrderRequiresProductIdAndQuantity(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Each item needs product_id and quantity');

        $this->service->createOrder(['items' => [['product_id' => 1]]]);
    }

    public function testCreateOrderRejectsZeroQuantity(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Quantity must be at least 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    public function testCreateOrderRejectsNegativeQuantity(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Quantity must be at least 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => -1]]]);
    }

    public function testCreateOrderThrowsOnProductNotFound(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(999)->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderThrowsOnInsufficientStock(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => '29.99', 'stock' => 2,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock for Widget');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderSuccess(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '29.99', 'stock' => 10];
        $createdOrder = [
            'id' => 1, 'status' => 'pending', 'total' => '59.98',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => '29.99', 'product_name' => 'Widget'],
            ],
        ];

        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('commit');
        $this->db->expects($this->never())->method('rollBack');

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepository->expects($this->once())->method('createOrderItem')
            ->with(1, 1, 2, 29.99);
        $this->orderRepository->expects($this->once())->method('decrementStock')
            ->with(1, 2);
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 59.98);
        $this->orderRepository->method('findByIdWithItems')->with(1)->willReturn($createdOrder);

        // Event published AFTER commit
        $this->eventPublisher->expects($this->once())->method('publish')
            ->with('order_created', $this->callback(function (array $data): bool {
                return $data['order_id'] === 1
                    && $data['total'] === 59.98
                    && isset($data['created_at']);
            }));

        // Cache invalidated AFTER commit
        $this->cache->expects($this->exactly(2))->method('del');

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ]);

        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['items']);
    }

    public function testCreateOrderWithMultipleItems(): void
    {
        $product1 = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 5];
        $product2 = ['id' => 2, 'name' => 'Gadget', 'price' => '20.00', 'stock' => 3];
        $createdOrder = [
            'id' => 1, 'status' => 'pending', 'total' => '50.00',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => '10.00', 'product_name' => 'Widget'],
                ['id' => 2, 'product_id' => 2, 'quantity' => 2, 'price' => '20.00', 'product_name' => 'Gadget'],
            ],
        ];

        $this->db->method('beginTransaction');
        $this->db->method('commit');
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')
            ->willReturnCallback(fn(int $id) => match($id) {
                1 => $product1,
                2 => $product2,
                default => null,
            });
        $this->orderRepository->method('findByIdWithItems')->willReturn($createdOrder);
        $this->eventPublisher->expects($this->once())->method('publish');

        $result = $this->service->createOrder([
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 2, 'quantity' => 2],
            ],
        ]);

        $this->assertEquals('50.00', $result['total']);
        $this->assertCount(2, $result['items']);
    }

    public function testCreateOrderRollsBackOnUnexpectedException(): void
    {
        $this->db->expects($this->once())->method('beginTransaction');
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');
        $this->orderRepository->method('createOrder')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order creation failed');

        $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);
    }

    public function testEventNotPublishedOnRollback(): void
    {
        $this->db->method('beginTransaction');
        $this->db->method('rollBack');
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn(null);

        $this->eventPublisher->expects($this->never())->method('publish');

        try {
            $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
        } catch (\InvalidArgumentException) {
            // Expected
        }
    }
}
