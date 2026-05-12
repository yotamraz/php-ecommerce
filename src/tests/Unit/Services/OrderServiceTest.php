<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\OrderRepository;
use App\Services\EventPublisher;
use App\Services\OrderService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Respect\Validation\Exceptions\NestedValidationException;

class OrderServiceTest extends TestCase
{
    private OrderRepository&MockObject $orderRepository;
    private EventPublisher&MockObject $eventPublisher;
    private MockObject $cache;
    private OrderService $service;
    private \PDO&MockObject $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(\PDO::class);
        $this->orderRepository = $this->createMock(OrderRepository::class);
        $this->orderRepository->method('getConnection')->willReturn($this->pdo);

        $this->eventPublisher = $this->createMock(EventPublisher::class);
        $this->cache = $this->getMockBuilder(RedisClient::class)
            ->disableOriginalConstructor()
            ->addMethods(['get', 'setex', 'del'])
            ->getMock();

        $this->service = new OrderService(
            $this->orderRepository,
            $this->eventPublisher,
            $this->cache,
        );
    }

    // --- Validation tests (Respect/Validation) ---

    public function testCreateOrderMissingItemsThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createOrder([]);
    }

    public function testCreateOrderItemsNotArrayThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createOrder(['items' => 'not-an-array']);
    }

    public function testCreateOrderEmptyItemsThrowsValidation(): void
    {
        $this->expectException(NestedValidationException::class);

        $this->service->createOrder(['items' => []]);
    }

    public function testCreateOrderItemMissingProductId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items[0]');

        $this->service->createOrder(['items' => [['quantity' => 1]]]);
    }

    public function testCreateOrderItemMissingQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items[0]');

        $this->service->createOrder(['items' => [['product_id' => 1]]]);
    }

    public function testCreateOrderItemZeroQuantityRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items[0]');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 0]]]);
    }

    public function testCreateOrderItemNegativeQuantityRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('items[0]');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => -5]]]);
    }

    // --- Business logic tests ---

    public function testCreateOrderProductNotFound(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);

        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Product 999 not found');

        $this->service->createOrder(['items' => [['product_id' => 999, 'quantity' => 1]]]);
    }

    public function testCreateOrderInsufficientStock(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('rollBack')->willReturn(true);

        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn([
            'id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => '2',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Insufficient stock for product 1');

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 5]]]);
    }

    public function testCreateOrderSuccess(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('commit')->willReturn(true);
        $this->pdo->expects($this->never())->method('rollBack');

        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => '50'];

        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->with(1)->willReturn($product);
        $this->orderRepository->expects($this->once())->method('insertOrderItem')
            ->with(1, 1, 2, 10.00);
        $this->orderRepository->expects($this->once())->method('decrementProductStock')
            ->with(1, 2);
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 20.00);

        $createdOrder = [
            'id' => 1, 'status' => 'pending', 'total' => '20.00',
            'items' => [['product_id' => 1, 'quantity' => 2, 'price' => '10.00']],
        ];
        $this->orderRepository->method('findById')->with(1)->willReturn($createdOrder);

        $this->eventPublisher->expects($this->once())->method('publish')
            ->with('order_created', $this->callback(function ($data) {
                return $data['order_id'] === 1 && $data['total'] === 20.00;
            }));

        $result = $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 2]]]);

        $this->assertEquals(1, $result['id']);
        $this->assertEquals('20.00', $result['total']);
    }

    public function testCreateOrderMultipleItems(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('commit')->willReturn(true);

        $product1 = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => '50'];
        $product2 = ['id' => 2, 'name' => 'Gadget', 'price' => '25.50', 'stock' => '30'];

        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')
            ->willReturnCallback(fn(int $id) => match($id) {
                1 => $product1,
                2 => $product2,
                default => null,
            });

        // Total: (10.00 * 2) + (25.50 * 3) = 20.00 + 76.50 = 96.50
        $this->orderRepository->expects($this->once())->method('updateOrderTotal')
            ->with(1, 96.50);

        $createdOrder = ['id' => 1, 'status' => 'pending', 'total' => '96.50', 'items' => []];
        $this->orderRepository->method('findById')->with(1)->willReturn($createdOrder);

        $this->eventPublisher->expects($this->once())->method('publish');

        $result = $this->service->createOrder([
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 2, 'quantity' => 3],
            ],
        ]);

        $this->assertEquals('96.50', $result['total']);
    }

    public function testCreateOrderRollsBackOnFailure(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdo->expects($this->never())->method('commit');

        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn(null);

        $this->eventPublisher->expects($this->never())->method('publish');

        $this->expectException(\RuntimeException::class);
        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
    }

    public function testCreateOrderEventPublishFailureDoesNotThrow(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => '50'];
        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn($product);

        $createdOrder = ['id' => 1, 'status' => 'pending', 'total' => '10.00', 'items' => []];
        $this->orderRepository->method('findById')->willReturn($createdOrder);

        // Event publish throws — should NOT propagate
        $this->eventPublisher->method('publish')
            ->willThrowException(new \RuntimeException('RabbitMQ down'));

        $result = $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);
        $this->assertEquals(1, $result['id']);
    }

    // --- Retrieval tests ---

    public function testListOrders(): void
    {
        $orders = [
            ['id' => 1, 'status' => 'pending', 'total' => '20.00', 'items' => []],
            ['id' => 2, 'status' => 'pending', 'total' => '50.00', 'items' => []],
        ];
        $this->orderRepository->method('findAll')->willReturn($orders);

        $result = $this->service->listOrders();

        $this->assertCount(2, $result);
        $this->assertEquals('20.00', $result[0]['total']);
    }

    public function testGetOrderFound(): void
    {
        $order = ['id' => 1, 'status' => 'pending', 'total' => '20.00', 'items' => []];
        $this->orderRepository->method('findById')->with(1)->willReturn($order);

        $result = $this->service->getOrder(1);

        $this->assertEquals(1, $result['id']);
    }

    public function testGetOrderNotFound(): void
    {
        $this->orderRepository->method('findById')->with(999)->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Order not found');

        $this->service->getOrder(999);
    }

    // --- Cache invalidation tests ---

    public function testCreateOrderInvalidatesProductCaches(): void
    {
        $this->pdo->method('beginTransaction')->willReturn(true);
        $this->pdo->method('commit')->willReturn(true);

        $product = ['id' => 1, 'name' => 'Widget', 'price' => '10.00', 'stock' => '50'];
        $this->orderRepository->method('insertOrder')->willReturn(1);
        $this->orderRepository->method('findProductForUpdate')->willReturn($product);
        $createdOrder = ['id' => 1, 'status' => 'pending', 'total' => '10.00', 'items' => []];
        $this->orderRepository->method('findById')->willReturn($createdOrder);
        $this->eventPublisher->method('publish');

        $deletedKeys = [];
        $this->cache->method('del')->willReturnCallback(function ($key) use (&$deletedKeys) {
            $deletedKeys[] = $key;
            return 1;
        });

        $this->service->createOrder(['items' => [['product_id' => 1, 'quantity' => 1]]]);

        $this->assertContains('products:all', $deletedKeys);
        $this->assertContains('products:1', $deletedKeys);
    }
}
