<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Functional tests for the Order API endpoints.
 * Requires MySQL, Redis, and RabbitMQ to be running.
 */
class OrderApiTest extends TestCase
{
    /**
     * Helper: create a product and return its data.
     */
    private function createProduct(string $name = 'Test Product', float $price = 29.99, int $stock = 100): array
    {
        $response = $this->request('POST', '/api/products', [
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
        ]);
        $this->assertEquals(201, $response->getStatusCode(), 'Failed to create test product');
        return $this->getResponseBody($response);
    }

    // --- List Orders ---

    public function testListOrdersReturns200(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertIsArray($body);
    }

    public function testListOrdersHasJsonContentType(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type'),
        );
    }

    // --- Get Order ---

    public function testGetOrderNotFoundReturns404(): void
    {
        $response = $this->request('GET', '/api/orders/99999');

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Order not found', $body['error']);
    }

    // --- Create Order ---

    public function testCreateOrderSuccess(): void
    {
        $product = $this->createProduct('Order Test Product', 25.00, 50);

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
        $product = $this->createProduct('Stock Decrement Product', 10.00, 20);

        $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 5],
            ],
        ]);

        // Check stock decreased
        $response = $this->request('GET', "/api/products/{$product['id']}");
        $body = $this->getResponseBody($response);
        $this->assertEquals(15, (int) $body['stock']);
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

    public function testCreateOrderMissingQuantity(): void
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
        $product = $this->createProduct('Low Stock Product', 10.00, 2);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 10]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('Insufficient stock', $body['error']);
    }

    public function testGetOrderAfterCreate(): void
    {
        $product = $this->createProduct('Retrievable Order Product', 15.00, 30);

        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 3],
            ],
        ]);
        $created = $this->getResponseBody($createResponse);
        $orderId = $created['id'];

        // Retrieve the order
        $response = $this->request('GET', "/api/orders/{$orderId}");

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals($orderId, $body['id']);
        $this->assertEquals(45.00, (float) $body['total']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
        $this->assertEquals('Retrievable Order Product', $body['items'][0]['product_name']);
    }

    public function testCreateOrderWithMultipleItems(): void
    {
        $product1 = $this->createProduct('Multi Item A', 10.00, 50);
        $product2 = $this->createProduct('Multi Item B', 20.00, 50);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product1['id'], 'quantity' => 2],
                ['product_id' => $product2['id'], 'quantity' => 1],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals(40.00, (float) $body['total']); // 10*2 + 20*1
        $this->assertCount(2, $body['items']);
    }
}
