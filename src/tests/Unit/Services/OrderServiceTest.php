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
    private OrderRepository&MockObject $orderRepository;
    private EventPublisher&MockObject $eventPublisher;
    private RedisClient&MockObject $cache;
    private OrderService $service;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->eventPublisher = $this->createMock(EventPublisher::class);
        $this->cache = $this->createMock(RedisClient::class);
        $this->service = new OrderService(
            $this->orderRepository,
            $this->eventPublisher,
            $this->cache,
        );
    }

    public function testListOrdersDelegatesToRepository(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'pending', 'total' => '59.98'],
            ['id' => 2, 'status' => 'pending', 'total' => '29.99'],
        ];
        $this->orderRepository->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
    }

    public function testGetOrderReturnsOrderWithItems(): void
    {
        $order = [
            'id' => 1,
            'status' => 'pending',
            'total' => '59.98',
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => '29.99', 'product_name' => 'Widget'],
            ],
        ];
        $this->orderRepository->method('findById')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['items']);
    }

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepository->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }

    public function testCreateOrderValidatesMissingItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items is required and must be an array');

        $this->service->createOrder([]);
    }

    public function testCreateOrderValidatesItemsNotArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items is required and must be an array');

        $this->service->createOrder(['items' => 'not-an-array']);
    }

    public function testCreateOrderValidatesEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items cannot be empty');

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderValidatesMissingProductId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product_id is required for item at index 0');

        $this->service->createOrder(['items' => [['quantity' => 1]]]);
    }

    public function testCreateOrderValidatesMissingQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity is required for item at index 0');

        $this->service->createOrder(['items' => [['product_id' => 1]]]);
    }

    public function testCreateOrderValidatesNonPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be a positive integer');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    public function testCreateOrderValidatesNegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be a positive integer');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => -1]]]);
    }

    public function testCreateOrderProductNotFound(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(999)->willReturn(null);
        $this->orderRepository->expects($this->once())->method('beginTransaction');
        $this->orderRepository->expects($this->once())->method('rollBack');
        $this->orderRepository->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderInsufficientStock(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '29.99', 'stock' => 2];
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepository->expects($this->once())->method('beginTransaction');
        $this->orderRepository->expects($this->once())->method('rollBack');
        $this->orderRepository->expects($this->never())->method('commit');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock for product Widget');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderSuccess(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '29.99', 'stock' => 10];
        $createdOrder = [
            'id' => 1,
            'status' => 'pending',
            'total' => '59.98',
            'items' => [
                ['product_id' => 1, 'quantity' => 2, 'price' => '29.99', 'product_name' => 'Widget'],
            ],
        ];

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepository->method('findById')->with(1)->willReturn($createdOrder);

        $this->orderRepository->expects($this->once())->method('beginTransaction');
        $this->orderRepository->expects($this->once())->method('commit');
        $this->orderRepository->expects($this->never())->method('rollBack');
        $this->orderRepository->expects($this->once())->method('createOrderItem')
            ->with(1, 1, 2, 29.99);
        $this->orderRepository->expects($this->once())->method('decrementStock')
            ->with(1, 2);
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 59.98);

        $this->eventPublisher->expects($this->once())->method('publish')
            ->with('order_created', $this->callback(function (array $data) {
                return $data['order_id'] === 1 && $data['total'] === 59.98;
            }));

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('59.98', $result['total']);
    }

    public function testCreateOrderMultipleItems(): void
    {
        $product1 = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 5];
        $product2 = ['id' => 2, 'name' => 'Gadget', 'price' => '20.00', 'stock' => 3];
        $createdOrder = [
            'id' => 1,
            'status' => 'pending',
            'total' => '50.00',
            'items' => [],
        ];

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')
            ->willReturnCallback(fn(int $id) => match ($id) {
                1 => $product1,
                2 => $product2,
                default => null,
            });
        $this->orderRepository->method('findById')->willReturn($createdOrder);

        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 50.0);

        $result = $this->service->createOrder([
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 2, 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(1, $result['id']);
    }

    public function testCreateOrderEventNotPublishedOnFailure(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn(null);

        $this->eventPublisher->expects($this->never())->method('publish');

        try {
            $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
        } catch (\RuntimeException) {
            // Expected
        }
    }

    public function testCreateOrderInvalidatesProductCaches(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 5];
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn($product);
        $this->orderRepository->method('findById')->willReturn(['id' => 1, 'items' => []]);

        // Expect cache invalidation for products:all and products:1
        $deletedKeys = [];
        $this->cache->method('del')->willReturnCallback(function ($key) use (&$deletedKeys) {
            $deletedKeys[] = $key;
            return 1;
        });

        $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);

        $this->assertContains('products:all', $deletedKeys);
        $this->assertContains('products:1', $deletedKeys);
    }
}
