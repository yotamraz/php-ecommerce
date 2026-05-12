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
     * Helper to create a product for order tests.
     *
     * @return array<string, mixed>
     */
    private function createProduct(string $name = 'Test Product', float $price = 29.99, int $stock = 100): array
    {
        $response = $this->request('POST', '/api/products', [
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
        ]);
        $this->assertEquals(201, $response->getStatusCode());
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
            $response->getHeaderLine('Content-Type'),
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

    // --- POST /api/orders validation ---

    public function testCreateOrderMissingItemsReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('items array is required', $body['error']);
    }

    public function testCreateOrderEmptyItemsReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('items array is required', $body['error']);
    }

    public function testCreateOrderItemMissingProductIdReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['quantity' => 2]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Each item needs product_id and quantity', $body['error']);
    }

    public function testCreateOrderItemMissingQuantityReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Each item needs product_id and quantity', $body['error']);
    }

    public function testCreateOrderZeroQuantityReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 0]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Quantity must be at least 1', $body['error']);
    }

    public function testCreateOrderNonexistentProductReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 99999, 'quantity' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('not found', $body['error']);
    }

    public function testCreateOrderInsufficientStockReturns400(): void
    {
        // Create a product with limited stock
        $product = $this->createProduct('Limited Product', 10.00, 2);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 100]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('Insufficient stock', $body['error']);
    }

    // --- POST /api/orders success ---

    public function testCreateOrderSuccessReturns201(): void
    {
        $product = $this->createProduct('Order Test Product', 25.00, 50);

        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 2]],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('id', $body);
        $this->assertEquals('pending', $body['status']);
        $this->assertEquals(50.00, (float) $body['total']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
    }

    public function testCreateOrderWithMultipleItems(): void
    {
        $product1 = $this->createProduct('Multi Item A', 10.00, 50);
        $product2 = $this->createProduct('Multi Item B', 20.00, 50);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product1['id'], 'quantity' => 3],
                ['product_id' => $product2['id'], 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        // Total = 10.00 * 3 + 20.00 * 2 = 70.00
        $this->assertEquals(70.00, (float) $body['total']);
        $this->assertCount(2, $body['items']);
    }

    public function testCreateOrderDecrementsStock(): void
    {
        $product = $this->createProduct('Stock Decrement Test', 15.00, 20);

        // Create order for 5 units
        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 5]],
        ]);

        // Check stock decreased
        $getResponse = $this->request('GET', "/api/products/{$product['id']}");
        $updatedProduct = $this->getResponseBody($getResponse);
        $this->assertEquals(15, (int) $updatedProduct['stock']);
    }

    // --- GET /api/orders/{id} with created order ---

    public function testCreatedOrderIsRetrievable(): void
    {
        $product = $this->createProduct('Retrievable Order Product', 30.00, 10);

        // Create order
        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $product['id'], 'quantity' => 1]],
        ]);
        $created = $this->getResponseBody($createResponse);
        $orderId = $created['id'];

        // Fetch it
        $response = $this->request('GET', "/api/orders/{$orderId}");

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals($orderId, $body['id']);
        $this->assertEquals(30.00, (float) $body['total']);
        $this->assertCount(1, $body['items']);
        $this->assertEquals($product['id'], (int) $body['items'][0]['product_id']);
        $this->assertArrayHasKey('product_name', $body['items'][0]);
    }
}
