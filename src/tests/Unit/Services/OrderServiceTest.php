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

    // --- listOrders ---

    public function testListOrdersDelegatesToRepository(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'pending', 'total' => 50.00],
            ['id' => 2, 'status' => 'pending', 'total' => 100.00],
        ];
        $this->orderRepository->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertEquals($orders, $result);
    }

    // --- getOrder ---

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepository->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }

    public function testGetOrderReturnsOrderWithItems(): void
    {
        $order = [
            'id' => 1,
            'status' => 'pending',
            'total' => 29.99,
            'items' => [
                ['id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => 29.99, 'product_name' => 'Mouse'],
            ],
        ];
        $this->orderRepository->method('findById')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Mouse', $result['items'][0]['product_name']);
    }

    // --- createOrder validation ---

    public function testCreateOrderRequiresItemsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder([]);
    }

    public function testCreateOrderRejectsEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderRejectsNonArrayItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder(['items' => 'not-an-array']);
    }

    public function testCreateOrderRejectsItemMissingProductId(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each item needs product_id and quantity');

        $this->service->createOrder(['items' => [['quantity' => 2]]]);
    }

    public function testCreateOrderRejectsItemMissingQuantity(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each item needs product_id and quantity');

        $this->service->createOrder(['items' => [['product_id' => 1]]]);
    }

    public function testCreateOrderRejectsZeroQuantity(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be at least 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    public function testCreateOrderRejectsNegativeQuantity(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be at least 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => -1]]]);
    }

    // --- createOrder business logic ---

    public function testCreateOrderRejectsNonexistentProduct(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(999)->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderRejectsInsufficientStock(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn([
            'id' => 1, 'name' => 'Mouse', 'price' => 29.99, 'stock' => 2,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock for Mouse');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderRollsBackOnValidationFailure(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn(null);
        $this->orderRepository->expects($this->once())->method('rollBack');
        $this->orderRepository->expects($this->never())->method('commit');

        try {
            $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
        } catch (\InvalidArgumentException $e) {
            // Expected
        }
    }

    // --- createOrder success ---

    public function testCreateOrderSuccessCommitsTransaction(): void
    {
        $product = ['id' => 1, 'name' => 'Mouse', 'price' => 29.99, 'stock' => 10];
        $order = [
            'id' => 1, 'status' => 'pending', 'total' => 59.98,
            'items' => [
                ['id' => 1, 'order_id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => 29.99, 'product_name' => 'Mouse'],
            ],
        ];

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepository->method('findById')->with(1)->willReturn($order);

        $this->orderRepository->expects($this->once())->method('beginTransaction');
        $this->orderRepository->expects($this->once())->method('commit');
        $this->orderRepository->expects($this->never())->method('rollBack');

        $this->orderRepository->expects($this->once())->method('insertOrderItem')
            ->with(1, 1, 2, 29.99);
        $this->orderRepository->expects($this->once())->method('decrementStock')
            ->with(1, 2);
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 59.98);

        $result = $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 2]]]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals(59.98, $result['total']);
    }

    public function testCreateOrderInvalidatesProductCaches(): void
    {
        $product = ['id' => 1, 'name' => 'Mouse', 'price' => 29.99, 'stock' => 10];
        $order = ['id' => 1, 'status' => 'pending', 'total' => 29.99, 'items' => []];

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn($product);
        $this->orderRepository->method('findById')->willReturn($order);

        // Expect cache invalidation for the specific product and the product list
        $this->cache->expects($this->exactly(2))->method('del')
            ->willReturnCallback(function (string $key) {
                $this->assertContains($key, ['products:1', 'products:all']);
                return 1;
            });

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
    }

    public function testCreateOrderPublishesEventAfterCommit(): void
    {
        $product = ['id' => 1, 'name' => 'Mouse', 'price' => 29.99, 'stock' => 10];
        $order = ['id' => 1, 'status' => 'pending', 'total' => 29.99, 'items' => []];

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn($product);
        $this->orderRepository->method('findById')->willReturn($order);

        $this->eventPublisher->expects($this->once())->method('publish')
            ->with(
                'order_created',
                $this->callback(function (array $data) {
                    return $data['order_id'] === 1
                        && $data['total'] === 29.99
                        && isset($data['created_at']);
                }),
            );

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
    }

    public function testCreateOrderDoesNotPublishEventOnFailure(): void
    {
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn(null);

        $this->eventPublisher->expects($this->never())->method('publish');

        try {
            $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
        } catch (\InvalidArgumentException $e) {
            // Expected
        }
    }

    public function testCreateOrderWithMultipleItems(): void
    {
        $mouse = ['id' => 1, 'name' => 'Mouse', 'price' => 29.99, 'stock' => 10];
        $keyboard = ['id' => 2, 'name' => 'Keyboard', 'price' => 89.99, 'stock' => 5];
        $order = ['id' => 1, 'status' => 'pending', 'total' => 119.98, 'items' => []];

        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')
            ->willReturnCallback(fn(int $id) => match ($id) {
                1 => $mouse,
                2 => $keyboard,
                default => null,
            });
        $this->orderRepository->method('findById')->willReturn($order);

        // Expect two insertOrderItem calls
        $insertCalls = [];
        $this->orderRepository->expects($this->exactly(2))->method('insertOrderItem')
            ->willReturnCallback(function () use (&$insertCalls) {
                $insertCalls[] = func_get_args();
            });

        // Total = 29.99 * 1 + 89.99 * 1 = 119.98
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 119.98);

        $result = $this->service->createOrder([
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 2, 'quantity' => 1],
            ],
        ]);

        $this->assertEquals(119.98, $result['total']);
    }
}
