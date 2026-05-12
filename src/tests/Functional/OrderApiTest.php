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
     * Helper: create a product for use in order tests.
     */
    private function createProduct(string $name = 'Order Test Product', float $price = 25.00, int $stock = 100): array
    {
        $response = $this->request('POST', '/api/products', [
            'name' => $name,
            'price' => $price,
            'stock' => $stock,
        ]);
        return $this->getResponseBody($response);
    }

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

    public function testGetOrderNotFoundReturns404(): void
    {
        $response = $this->request('GET', '/api/orders/99999');

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Order not found', $body['error']);
    }

    public function testCreateOrderSuccess(): void
    {
        $product = $this->createProduct('Orderable Widget', 15.00, 50);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('id', $body);
        $this->assertEquals('pending', $body['status']);
        $this->assertEquals(30.00, (float) $body['total']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
    }

    public function testCreateOrderDecrementsStock(): void
    {
        $product = $this->createProduct('Stock Check Product', 10.00, 20);

        // Create an order for 5 units
        $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 5],
            ],
        ]);

        // Verify stock was decremented
        $response = $this->request('GET', '/api/products/' . $product['id']);
        $body = $this->getResponseBody($response);
        $this->assertEquals(15, (int) $body['stock']);
    }

    public function testCreateOrderMissingItems(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Order must contain items', $body['error']);
    }

    public function testCreateOrderEmptyItems(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Order must contain items', $body['error']);
    }

    public function testCreateOrderInvalidQuantity(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 0],
            ],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('quantity must be greater than zero', $body['error']);
    }

    public function testCreateOrderProductNotFound(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 99999, 'quantity' => 1],
            ],
        ]);

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('not found', $body['error']);
    }

    public function testCreateOrderInsufficientStock(): void
    {
        $product = $this->createProduct('Low Stock Product', 5.00, 2);

        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 10],
            ],
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('Insufficient stock', $body['error']);
    }

    public function testGetOrderAfterCreate(): void
    {
        $product = $this->createProduct('Retrievable Product', 20.00, 30);

        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 3],
            ],
        ]);
        $created = $this->getResponseBody($createResponse);
        $orderId = $created['id'];

        // Fetch the order
        $response = $this->request('GET', '/api/orders/' . $orderId);

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals($orderId, $body['id']);
        $this->assertEquals(60.00, (float) $body['total']);
        $this->assertCount(1, $body['items']);
        $this->assertEquals($product['id'], (int) $body['items'][0]['product_id']);
    }

    public function testCreateOrderMultipleItems(): void
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
        $this->assertEquals(40.00, (float) $body['total']); // 2*10 + 1*20
        $this->assertCount(2, $body['items']);
    }

    public function testDeleteProductReferencedByOrderReturns409(): void
    {
        $product = $this->createProduct('Referenced Product', 10.00, 50);

        // Create an order referencing this product
        $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => $product['id'], 'quantity' => 1],
            ],
        ]);

        // Try to delete the product — should fail with 409
        $response = $this->request('DELETE', '/api/products/' . $product['id']);

        $this->assertEquals(409, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('referenced by orders', $body['error']);
    }
}
