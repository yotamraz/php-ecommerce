<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Functional tests for order API endpoints.
 * Exercises the full Slim middleware pipeline and DI wiring.
 */
class OrderApiTest extends TestCase
{
    // ── GET /api/orders ──────────────────────────────────────────────

    public function testListOrdersReturns200(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertEquals(200, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertIsArray($body);
    }

    public function testListOrdersReturnsJson(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
    }

    // ── GET /api/orders/{id} ─────────────────────────────────────────

    public function testGetOrderNotFoundReturns404(): void
    {
        $response = $this->request('GET', '/api/orders/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString('not found', strtolower($body['error']));
    }

    // ── POST /api/orders ─────────────────────────────────────────────

    public function testCreateOrderWithoutItemsReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString('items', strtolower($body['error']));
    }

    public function testCreateOrderWithEmptyItemsReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('error', $body);
    }

    public function testCreateOrderMissingProductIdReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['quantity' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString('product_id', strtolower($body['error']));
    }

    public function testCreateOrderMissingQuantityReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('error', $body);
        $this->assertStringContainsString('quantity', strtolower($body['error']));
    }

    public function testCreateOrderZeroQuantityReturns400(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 0]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testCreateOrderNonexistentProductReturnsError(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 99999, 'quantity' => 1]],
        ]);

        // Should return 404 for product not found
        $this->assertContains($response->getStatusCode(), [404, 400]);
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('error', $body);
    }

    public function testCreateOrderSuccessReturns201(): void
    {
        // First create a product with enough stock
        $productResponse = $this->request('POST', '/api/products', [
            'name' => 'Order Test Product',
            'price' => 15.50,
            'stock' => 100,
        ]);
        $product = $this->getResponseBody($productResponse);
        $productId = $product['id'];

        // Create an order for that product
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $productId, 'quantity' => 2]],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
    }

    public function testCreateOrderDecrementsStock(): void
    {
        // Create a product with known stock
        $productResponse = $this->request('POST', '/api/products', [
            'name' => 'Stock Decrement Test',
            'price' => 10.00,
            'stock' => 50,
        ]);
        $product = $this->getResponseBody($productResponse);
        $productId = $product['id'];

        // Place an order for 3 units
        $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $productId, 'quantity' => 3]],
        ]);

        // Verify stock decremented
        $getResponse = $this->request('GET', "/api/products/{$productId}");
        $updatedProduct = $this->getResponseBody($getResponse);
        $this->assertEquals(47, $updatedProduct['stock']);
    }

    public function testCreateOrderCalculatesCorrectTotal(): void
    {
        // Create a product
        $productResponse = $this->request('POST', '/api/products', [
            'name' => 'Total Calc Test',
            'price' => 25.00,
            'stock' => 100,
        ]);
        $product = $this->getResponseBody($productResponse);
        $productId = $product['id'];

        // Order 4 units at $25 each = $100
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $productId, 'quantity' => 4]],
        ]);

        $body = $this->getResponseBody($response);
        $this->assertEquals(100.00, (float) $body['total']);
    }

    public function testGetCreatedOrderReturnsCorrectData(): void
    {
        // Create a product
        $productResponse = $this->request('POST', '/api/products', [
            'name' => 'Get Order Test',
            'price' => 19.99,
            'stock' => 20,
        ]);
        $product = $this->getResponseBody($productResponse);
        $productId = $product['id'];

        // Create an order
        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => $productId, 'quantity' => 1]],
        ]);
        $createdOrder = $this->getResponseBody($createResponse);
        $orderId = $createdOrder['id'];

        // Get the order
        $getResponse = $this->request('GET', "/api/orders/{$orderId}");

        $this->assertEquals(200, $getResponse->getStatusCode());
        $body = $this->getResponseBody($getResponse);
        $this->assertEquals($orderId, $body['id']);
        $this->assertEquals('pending', $body['status']);
        $this->assertArrayHasKey('items', $body);
        $this->assertCount(1, $body['items']);
    }
}
