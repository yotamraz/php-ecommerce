<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Functional tests for the Order API endpoints.
 * Requires MySQL, Redis, and RabbitMQ to be running.
 */
class OrderApiTest extends TestCase
{
    // ── Helper ─────────────────────────────────────────────────────

    /**
     * Create a product for order testing, returns its data including 'id'.
     */
    private function createProduct(string $name = 'Order Test Product', float $price = 10.00, int $stock = 100): array
    {
        $response = $this->request('POST', '/api/products', [
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
        ]);
        $this->assertEquals(201, $response->getStatusCode(), 'Product setup failed');
        return $this->getResponseBody($response);
    }

    // ── POST /api/orders ──────────────────────────────────────────

    public function testCreateOrderSuccess(): void
    {
        $product = $this->createProduct('Widget', 25.00, 50);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('id', $body);
        $this->assertEquals(50.00, (float) $body['total']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
        $this->assertEquals($product['id'], (int) $body['items'][0]['product_id']);
        $this->assertEquals(2, (int) $body['items'][0]['quantity']);
    }

    public function testCreateOrderDecrementsStock(): void
    {
        $product = $this->createProduct('Stock Test', 5.00, 20);

        $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 3],
            ],
        ]);

        // Fetch the product and verify stock was decremented
        $getResponse = $this->request('GET', "/api/products/{$product['id']}");
        $updated = $this->getResponseBody($getResponse);
        $this->assertEquals(17, (int) $updated['stock']);
    }

    public function testCreateOrderMultipleItems(): void
    {
        $product1 = $this->createProduct('Multi Item 1', 10.00, 50);
        $product2 = $this->createProduct('Multi Item 2', 20.00, 30);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product1['id'], 'quantity' => 1],
                ['product_id' => $product2['id'], 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals(50.00, (float) $body['total']); // 10 + 2*20
        $this->assertCount(2, $body['items']);
    }

    public function testCreateOrderMissingItems(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('items array is required', $body['error']);
    }

    public function testCreateOrderEmptyItems(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('items array is required', $body['error']);
    }

    public function testCreateOrderItemMissingProductId(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['quantity' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Each item needs product_id and quantity', $body['error']);
    }

    public function testCreateOrderItemMissingQuantity(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Each item needs product_id and quantity', $body['error']);
    }

    public function testCreateOrderZeroQuantity(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 0]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Quantity must be at least 1', $body['error']);
    }

    public function testCreateOrderProductNotFound(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 99999, 'quantity' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('Product 99999 not found', $body['error']);
    }

    public function testCreateOrderInsufficientStock(): void
    {
        $product = $this->createProduct('Low Stock', 10.00, 2);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 5]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('Insufficient stock', $body['error']);
    }

    public function testCreateOrderJsonContentType(): void
    {
        $product = $this->createProduct('Content Type Test', 10.00, 50);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ]);

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
    }

    // ── GET /api/orders ───────────────────────────────────────────

    public function testListOrdersReturns200(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertIsArray($body);
    }

    public function testListOrdersJsonContentType(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
    }

    // ── GET /api/orders/{id} ──────────────────────────────────────

    public function testGetOrderReturnsOrderWithItems(): void
    {
        $product = $this->createProduct('Get Order Test', 15.00, 30);

        // Create an order first
        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ]);
        $created = $this->getResponseBody($createResponse);
        $orderId = $created['id'];

        // Fetch it
        $response = $this->request('GET', "/api/orders/{$orderId}");

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals($orderId, (int) $body['id']);
        $this->assertEquals(30.00, (float) $body['total']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
        $this->assertArrayHasKey('product_name', $body['items'][0]);
        $this->assertEquals('Get Order Test', $body['items'][0]['product_name']);
    }

    public function testGetOrderNotFoundReturns404(): void
    {
        $response = $this->request('GET', '/api/orders/99999');

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Order not found', $body['error']);
    }
}
