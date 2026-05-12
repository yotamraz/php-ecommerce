<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Functional tests for order API endpoints.
 * Exercises the full Slim middleware pipeline and DI wiring.
 */
class OrderApiTest extends TestCase
{
    // --- POST /api/orders ---

    public function testCreateOrderMissingItems(): void
    {
        $response = $this->request('POST', '/api/orders', []);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('items', $body['error']);
    }

    public function testCreateOrderEmptyItems(): void
    {
        $response = $this->request('POST', '/api/orders', ['items' => []]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('empty', $body['error']);
    }

    public function testCreateOrderMissingProductId(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['quantity' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('product_id', $body['error']);
    }

    public function testCreateOrderMissingQuantity(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('quantity', $body['error']);
    }

    public function testCreateOrderZeroQuantity(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 0]],
        ]);

        $this->assertEquals(400, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('quantity', $body['error']);
    }

    public function testCreateOrderNonExistentProduct(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 99999, 'quantity' => 1]],
        ]);

        // 404 because product not found (handled by middleware from RuntimeException)
        $this->assertContains($response->getStatusCode(), [404]);
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('not found', $body['error']);
    }

    public function testCreateOrderSuccess(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('id', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertArrayHasKey('items', $body);
        $this->assertEquals('pending', $body['status']);
        $this->assertNotEmpty($body['items']);
    }

    public function testCreateOrderMultipleItems(): void
    {
        $response = $this->request('POST', '/api/orders', [
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 2, 'quantity' => 2],
            ],
        ]);

        $this->assertEquals(201, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertCount(2, $body['items']);
        $this->assertGreaterThan(0, (float) $body['total']);
    }

    // --- GET /api/orders ---

    public function testListOrders(): void
    {
        $response = $this->request('GET', '/api/orders');

        $this->assertEquals(200, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertIsArray($body);
    }

    // --- GET /api/orders/{id} ---

    public function testGetOrderNotFound(): void
    {
        $response = $this->request('GET', '/api/orders/99999');

        $this->assertEquals(404, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertStringContainsString('not found', $body['error']);
    }

    public function testGetOrderSuccess(): void
    {
        // First create an order
        $createResponse = $this->request('POST', '/api/orders', [
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);
        $created = $this->getResponseBody($createResponse);

        // Then retrieve it
        $response = $this->request('GET', '/api/orders/' . $created['id']);

        $this->assertEquals(200, $response->getStatusCode());
        $body = $this->getResponseBody($response);
        $this->assertEquals($created['id'], $body['id']);
        $this->assertArrayHasKey('items', $body);
    }
}
