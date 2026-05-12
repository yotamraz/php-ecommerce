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

    // --- GET /api/orders ---

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
            $response->getHeaderLine('Content-Type')
        );
    }

    // --- GET /api/orders/{id} ---

    public function testGetOrderNotFoundReturns404(): void
    {
        $response = $this->request('GET', '/api/orders/99999');

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Order not found', $body['error']);
    }

    // --- POST /api/orders ---

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
        $this->assertEquals('pending', $body['status']);
        $this->assertEquals(50.00, (float) $body['total']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
    }

    public function testCreateOrderMultipleItems(): void
    {
        $product1 = $this->createProduct('Multi Item A', 10.00, 20);
        $product2 = $this->createProduct('Multi Item B', 20.00, 15);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product1['id'], 'quantity' => 1],
                ['product_id' => $product2['id'], 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals(50.00, (float) $body['total']);
        $this->assertCount(2, $body['items']);
    }

    public function testCreateOrderDecrementsStock(): void
    {
        $product = $this->createProduct('Stock Check Product', 15.00, 10);

        $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 3],
            ],
        ]);

        // Verify stock decremented
        $getResponse = $this->request('GET', "/api/products/{$product['id']}");
        $updatedProduct = $this->getResponseBody($getResponse);
        $this->assertEquals(7, (int) $updatedProduct['stock']);
    }

    public function testGetOrderAfterCreate(): void
    {
        $product = $this->createProduct('Fetchable Order Product', 30.00, 20);

        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 1],
            ],
        ]);
        $created = $this->getResponseBody($createResponse);

        // Fetch the order
        $response = $this->request('GET', "/api/orders/{$created['id']}");

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals($created['id'], $body['id']);
        $this->assertEquals('pending', $body['status']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
        $this->assertEquals($product['id'], (int) $body['items'][0]['product_id']);
    }

    // --- Validation errors ---

    public function testCreateOrderMissingItemsReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('items', $body['error']);
    }

    public function testCreateOrderEmptyItemsReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('items cannot be empty', $body['error']);
    }

    public function testCreateOrderMissingProductIdReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['quantity' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('product_id', $body['error']);
    }

    public function testCreateOrderMissingQuantityReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('quantity', $body['error']);
    }

    public function testCreateOrderZeroQuantityReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 0]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('quantity', $body['error']);
    }

    public function testCreateOrderProductNotFoundReturns404(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 99999, 'quantity' => 1]],
        ]);

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('not found', $body['error']);
    }

    public function testCreateOrderInsufficientStockReturns400(): void
    {
        $product = $this->createProduct('Low Stock Product', 10.00, 2);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 5]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('Insufficient stock', $body['error']);
    }

    public function testCreateOrderHasJsonContentType(): void
    {
        $product = $this->createProduct('Content Type Product', 10.00, 5);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ]);

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
    }
}
