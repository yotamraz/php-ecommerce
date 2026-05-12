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

    // ── List / Get ───────────────────────────────────────────────────

    public function testListOrdersReturnsAllOrders(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'pending', 'total' => '59.98'],
            ['id' => 2, 'status' => 'pending', 'total' => '29.99'],
        ];
        $this->orderRepo->method('findAll')->willReturn($orders);

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
                ['id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => '29.99'],
            ],
        ];
        $this->orderRepo->method('findById')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertEquals(1, $result['id']);
        $this->assertCount(1, $result['items']);
    }

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepo->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }

    // ── Validation ───────────────────────────────────────────────────

    public function testCreateOrderRequiresItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items is required');

        $this->service->createOrder([]);
    }

    public function testCreateOrderRejectsEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items is required');

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderRequiresProductId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product_id');

        $this->service->createOrder(['items' => [['quantity' => 1]]]);
    }

    public function testCreateOrderRequiresQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity');

        $this->service->createOrder(['items' => [['product_id' => 1]]]);
    }

    public function testCreateOrderRejectsZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    // ── Transactional creation ───────────────────────────────────────

    public function testCreateOrderProductNotFoundRollsBack(): void
    {
        $this->orderRepo->expects($this->once())->method('beginTransaction');
        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->with(999)->willReturn(null);
        $this->orderRepo->expects($this->once())->method('rollBack');
        $this->orderRepo->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product not found: 999');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderInsufficientStockRollsBack(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 2];

        $this->orderRepo->expects($this->once())->method('beginTransaction');
        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepo->expects($this->once())->method('rollBack');
        $this->orderRepo->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderSuccessful(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '29.99', 'stock' => 10];
        $createdOrder = [
            'id' => 1,
            'status' => 'pending',
            'total' => '59.98',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => '29.99', 'product_name' => 'Widget'],
            ],
        ];

        $this->orderRepo->expects($this->once())->method('beginTransaction');
        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepo->expects($this->once())
            ->method('insertOrderItem')
            ->with(1, 1, 2, 29.99);
        $this->orderRepo->expects($this->once())
            ->method('decrementProductStock')
            ->with(1, 2);
        $this->orderRepo->expects($this->once())
            ->method('updateOrderTotal')
            ->with(1, 59.98);
        $this->orderRepo->expects($this->once())->method('commit');
        $this->orderRepo->expects($this->never())->method('rollBack');
        $this->orderRepo->method('findById')->with(1)->willReturn($createdOrder);

        // Event should be published AFTER commit
        $this->eventPublisher->expects($this->once())
            ->method('publish')
            ->with('order_created', $this->callback(function (array $data) {
                return $data['order_id'] === 1
                    && $data['total'] === 59.98
                    && $data['items_count'] === 1;
            }));

        // Cache should be invalidated
        $this->cache->expects($this->exactly(2))->method('del');

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('59.98', $result['total']);
    }

    public function testCreateOrderEventPublishFailureDoesNotRollBack(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 5];
        $createdOrder = [
            'id' => 1,
            'status' => 'pending',
            'total' => '10.00',
            'items' => [
                ['id' => 1, 'product_id' => 1, 'quantity' => 1, 'price' => '10.00', 'product_name' => 'Widget'],
            ],
        ];

        $this->orderRepo->method('createOrder')->willReturn(1);
        $this->orderRepo->method('findProductForUpdate')->willReturn($product);
        $this->orderRepo->method('findById')->willReturn($createdOrder);

        // Event publishing fails — should NOT throw
        $this->eventPublisher->method('publish')
            ->willThrowException(new \RuntimeException('Queue down'));

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);

        // Order should still be returned successfully
        $this->assertEquals(1, $result['id']);
    }
}
