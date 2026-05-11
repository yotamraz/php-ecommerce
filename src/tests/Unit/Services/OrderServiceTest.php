<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\EventPublisher;
use App\Services\OrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

class OrderServiceTest extends TestCase
{
    private OrderRepository&MockObject $orderRepo;
    private ProductRepository&MockObject $productRepo;
    private RedisClient&MockObject $cache;
    private EventPublisher&MockObject $eventPublisher;
    private OrderService $service;

    protected function setUp(): void
    {
        $this->orderRepo = $this->createMock(OrderRepository::class);
        $this->productRepo = $this->createMock(ProductRepository::class);
        $this->cache = $this->createMock(RedisClient::class);
        $this->eventPublisher = $this->createMock(EventPublisher::class);

        $this->service = new OrderService(
            $this->orderRepo,
            $this->productRepo,
            $this->cache,
            $this->eventPublisher,
        );
    }

    // ── listOrders ────────────────────────────────────────────────

    public function testListOrdersDelegatesToRepository(): void
    {
        $orders = [
            ['id' => 2, 'total' => 100.00, 'status' => 'pending'],
            ['id' => 1, 'total' => 50.00, 'status' => 'pending'],
        ];
        $this->orderRepo->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertCount(2, $result);
        $this->assertEquals(2, $result[0]['id']);
    }

    // ── getOrder ──────────────────────────────────────────────────

    public function testGetOrderReturnsOrderWithItems(): void
    {
        $order = [
            'id' => 1,
            'total' => 59.98,
            'status' => 'pending',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => 29.99, 'product_name' => 'Widget'],
            ],
        ];
        $this->orderRepo->method('findById')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['items']);
    }

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepo->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }

    // ── createOrder validation ────────────────────────────────────

    public function testCreateOrderRequiresItemsArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items array is required');

        $this->service->createOrder([]);
    }

    public function testCreateOrderRejectsEmptyItemsArray(): void
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

    public function testCreateOrderRequiresProductIdAndQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Each item needs product_id and quantity');

        $this->service->createOrder(['items' => [['product_id' => 1]]]);
    }

    public function testCreateOrderRejectsZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be at least 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    public function testCreateOrderRejectsNegativeQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be at least 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => -2]]]);
    }

    // ── createOrder business logic ────────────────────────────────

    public function testCreateOrderProductNotFound(): void
    {
        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->with(999)->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderInsufficientStock(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => 10.00, 'stock' => 2];

        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->with(1)->willReturn($product);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient stock for Widget');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderRollsBackOnValidationFailure(): void
    {
        $this->orderRepo->expects($this->once())->method('beginTransaction');
        $this->orderRepo->expects($this->once())->method('rollBack');
        $this->orderRepo->expects($this->never())->method('commit');

        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->willReturn(null);

        $this->eventPublisher->expects($this->never())->method('publish');

        try {
            $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
        } catch (\InvalidArgumentException) {
            // Expected
        }
    }

    public function testCreateOrderSuccess(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => 29.99, 'stock' => 10];
        $createdOrder = [
            'id' => 1,
            'total' => 59.98,
            'status' => 'pending',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => 29.99, 'product_name' => 'Widget'],
            ],
        ];

        $this->orderRepo->expects($this->once())->method('beginTransaction');
        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepo->expects($this->once())->method('insertOrderItem')
            ->with(1, 1, 2, 29.99);
        $this->orderRepo->expects($this->once())->method('decrementProductStock')
            ->with(1, 2);
        $this->orderRepo->expects($this->once())->method('updateOrderTotal')
            ->with(1, 59.98);
        $this->orderRepo->expects($this->once())->method('commit');
        $this->orderRepo->expects($this->never())->method('rollBack');

        // After commit: cache invalidation and event publishing
        $this->orderRepo->method('findById')->with(1)->willReturn($createdOrder);

        $this->eventPublisher->expects($this->once())->method('publish')
            ->with('order_created', $this->callback(function (array $data) {
                return $data['order_id'] === 1
                    && $data['total'] === 59.98
                    && isset($data['created_at']);
            }));

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals(59.98, $result['total']);
        $this->assertCount(1, $result['items']);
    }

    public function testCreateOrderMultipleItems(): void
    {
        $product1 = ['id' => 1, 'name' => 'Widget', 'price' => 10.00, 'stock' => 50];
        $product2 = ['id' => 2, 'name' => 'Gadget', 'price' => 20.00, 'stock' => 30];
        $createdOrder = [
            'id' => 1,
            'total' => 50.00,
            'status' => 'pending',
            'items' => [
                ['product_id' => 1, 'quantity' => 1, 'price' => 10.00, 'product_name' => 'Widget'],
                ['product_id' => 2, 'quantity' => 2, 'price' => 20.00, 'product_name' => 'Gadget'],
            ],
        ];

        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')
            ->willReturnCallback(fn(int $id) => match ($id) {
                1 => $product1,
                2 => $product2,
                default => null,
            });
        $this->orderRepo->expects($this->once())->method('updateOrderTotal')
            ->with(1, 50.00);
        $this->orderRepo->method('findById')->with(1)->willReturn($createdOrder);
        $this->eventPublisher->expects($this->once())->method('publish');

        $result = $this->service->createOrder([
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 2, 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(50.00, $result['total']);
        $this->assertCount(2, $result['items']);
    }

    public function testCreateOrderSuccessReturnsCreatedOrder(): void
    {
        // Verify that after a successful order, the service fetches and returns the order
        $product = ['id' => 3, 'name' => 'Test', 'price' => 5.00, 'stock' => 100];
        $createdOrder = [
            'id' => 1,
            'total' => 5.00,
            'status' => 'pending',
            'items' => [
                ['product_id' => 3, 'quantity' => 1, 'price' => 5.00, 'product_name' => 'Test'],
            ],
        ];

        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->willReturn($product);
        $this->orderRepo->method('findById')->with(1)->willReturn($createdOrder);
        $this->eventPublisher->expects($this->once())->method('publish');

        $result = $this->service->createOrder([
            'items' => [['product_id' => 3, 'quantity' => 1]],
        ]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals(5.00, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Test', $result['items'][0]['product_name']);
    }
}
