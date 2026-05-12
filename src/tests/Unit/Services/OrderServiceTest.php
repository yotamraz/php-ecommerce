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
    private \PDO&MockObject $db;
    private OrderRepository&MockObject $orderRepository;
    private ProductRepository&MockObject $productRepository;
    private RedisClient&MockObject $cache;
    private EventPublisher&MockObject $eventPublisher;
    private OrderService $service;

    protected function setUp(): void
    {
        $this->db = $this->createMock(\PDO::class);
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->productRepository = $this->createMock(ProductRepository::class);
        $this->cache = $this->createMock(RedisClient::class);
        $this->eventPublisher = $this->createMock(EventPublisher::class);

        $this->service = new OrderService(
            $this->db,
            $this->orderRepository,
            $this->productRepository,
            $this->cache,
            $this->eventPublisher,
        );
    }

    public function testListOrdersDelegatesToRepository(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'pending', 'total' => '29.99'],
            ['id' => 2, 'status' => 'shipped', 'total' => '59.98'],
        ];
        $this->orderRepository->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
    }

    public function testGetOrderDelegatesToRepository(): void
    {
        $order = ['id' => 1, 'status' => 'pending', 'total' => '29.99', 'items' => []];
        $this->orderRepository->method('findById')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertEquals(1, $result['id']);
    }

    public function testGetOrderReturnsNullWhenNotFound(): void
    {
        $this->orderRepository->method('findById')->with(999)->willReturn(null);

        $result = $this->service->getOrder(999);

        $this->assertNull($result);
    }

    public function testCreateOrderRequiresItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order must contain items');

        $this->service->createOrder([]);
    }

    public function testCreateOrderRequiresNonEmptyItems(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Order must contain items');

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderRequiresProductId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('product_id is required');

        $this->service->createOrder(['items' => [['quantity' => 1]]]);
    }

    public function testCreateOrderRequiresPositiveQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quantity must be greater than zero');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    public function testCreateOrderProductNotFound(): void
    {
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('inTransaction')->willReturn(true);
        $this->db->method('rollBack')->willReturn(true);
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->productRepository->method('findByIdForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderInsufficientStock(): void
    {
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('inTransaction')->willReturn(true);
        $this->db->method('rollBack')->willReturn(true);
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->productRepository->method('findByIdForUpdate')->with(1)->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => '9.99', 'stock' => 2,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for product 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderSuccess(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 50];
        $createdOrder = [
            'id' => 1, 'status' => 'pending', 'total' => '20.00',
            'items' => [['product_id' => 1, 'quantity' => 2, 'price' => '10.00']],
        ];

        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('commit')->willReturn(true);
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->productRepository->method('findByIdForUpdate')->with(1)->willReturn($product);
        $this->orderRepository->expects($this->once())->method('createOrderItem')
            ->with(1, 1, 2, 10.0);
        $this->productRepository->expects($this->once())->method('decrementStock')
            ->with(1, 2);
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 20.0);
        $this->orderRepository->method('findById')->with(1)->willReturn($createdOrder);

        // Event should be published after commit
        $this->eventPublisher->expects($this->once())->method('publish')
            ->with('order_created', ['order_id' => 1, 'total' => 20.0]);

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 2]],
        ]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('20.00', $result['total']);
    }

    public function testCreateOrderRollsBackOnFailure(): void
    {
        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('inTransaction')->willReturn(true);
        $this->db->expects($this->once())->method('rollBack')->willReturn(true);
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->productRepository->method('findByIdForUpdate')
            ->willThrowException(new \PDOException('DB error'));

        // Event should NOT be published
        $this->eventPublisher->expects($this->never())->method('publish');

        $this->expectException(\PDOException::class);

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
    }

    public function testCreateOrderEventPublishFailureDoesNotFailOrder(): void
    {
        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => 50];
        $createdOrder = [
            'id' => 1, 'status' => 'pending', 'total' => '10.00',
            'items' => [['product_id' => 1, 'quantity' => 1, 'price' => '10.00']],
        ];

        $this->db->method('beginTransaction')->willReturn(true);
        $this->db->method('commit')->willReturn(true);
        $this->orderRepository->method('createOrder')->willReturn(1);
        $this->productRepository->method('findByIdForUpdate')->willReturn($product);
        $this->orderRepository->method('findById')->with(1)->willReturn($createdOrder);

        // Event publishing fails — order should still succeed
        $this->eventPublisher->method('publish')
            ->willThrowException(new \RuntimeException('RabbitMQ down'));

        $result = $this->service->createOrder([
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);

        $this->assertEquals(1, $result['id']);
    }
}
