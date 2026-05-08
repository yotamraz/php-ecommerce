<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Functional tests for the Product API endpoints.
 * Requires MySQL and Redis to be running.
 */
class ProductApiTest extends TestCase
{
    public function testListProductsReturns200(): void
    {
        $response = $this->request('GET', '/api/products');

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertIsArray($body);
    }

    public function testListProductsHasJsonContentType(): void
    {
        $response = $this->request('GET', '/api/products');

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
    }

    public function testGetProductNotFoundReturns404(): void
    {
        $response = $this->request('GET', '/api/products/99999');

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Product not found', $body['error']);
    }

    public function testCreateProductSuccess(): void
    {
        $response = $this->request('POST', '/api/products', [
            'name' => 'PHPUnit Test Product',
            'price' => 19.99,
            'stock' => 50,
            'description' => 'Created by test',
        ]);

        $this->assertEquals(201, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('PHPUnit Test Product', $body['name']);
        $this->assertEquals(19.99, (float) $body['price']);
        $this->assertEquals(50, (int) $body['stock']);
        $this->assertArrayHasKey('id', $body);
    }

    public function testCreateProductMissingRequiredFields(): void
    {
        $response = $this->request('POST', '/api/products', []);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('name and price are required', $body['error']);
    }

    public function testCreateProductNegativePrice(): void
    {
        $response = $this->request('POST', '/api/products', [
            'name' => 'Bad Product',
            'price' => -5,
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('price must be greater than zero', $body['error']);
    }

    public function testCreateProductNegativeStock(): void
    {
        $response = $this->request('POST', '/api/products', [
            'name' => 'Bad Stock Product',
            'price' => 10,
            'stock' => -1,
        ]);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('stock cannot be negative', $body['error']);
    }

    public function testGetProductAfterCreate(): void
    {
        // Create a product
        $createResponse = $this->request('POST', '/api/products', [
            'name' => 'Fetchable Product',
            'price' => 29.99,
            'stock' => 10,
        ]);
        $created = $this->getResponseBody($createResponse);
        $id = $created['id'];

        // Fetch it
        $response = $this->request('GET', "/api/products/{$id}");

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals($id, $body['id']);
        $this->assertEquals('Fetchable Product', $body['name']);
    }

    public function testUpdateProductSuccess(): void
    {
        // Create a product first
        $createResponse = $this->request('POST', '/api/products', [
            'name' => 'Updatable Product',
            'price' => 15.00,
            'stock' => 5,
        ]);
        $created = $this->getResponseBody($createResponse);
        $id = $created['id'];

        // Update it
        $response = $this->request('PUT', "/api/products/{$id}", [
            'name' => 'Updated Product',
            'price' => 20.00,
        ]);

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Updated Product', $body['name']);
        $this->assertEquals(20.00, (float) $body['price']);
    }

    public function testUpdateProductNotFound(): void
    {
        $response = $this->request('PUT', '/api/products/99999', [
            'name' => 'Ghost',
        ]);

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Product not found', $body['error']);
    }

    public function testUpdateProductNoFields(): void
    {
        // Create a product first
        $createResponse = $this->request('POST', '/api/products', [
            'name' => 'NoUpdate Product',
            'price' => 10.00,
        ]);
        $created = $this->getResponseBody($createResponse);
        $id = $created['id'];

        $response = $this->request('PUT', "/api/products/{$id}", []);

        $this->assertEquals(400, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('No fields to update', $body['error']);
    }

    public function testDeleteProductSuccess(): void
    {
        // Create a product first
        $createResponse = $this->request('POST', '/api/products', [
            'name' => 'Deletable Product',
            'price' => 5.00,
        ]);
        $created = $this->getResponseBody($createResponse);
        $id = $created['id'];

        // Delete it
        $response = $this->request('DELETE', "/api/products/{$id}");
        $this->assertEquals(204, $response->getStatusCode());

        // Verify it's gone
        $getResponse = $this->request('GET', "/api/products/{$id}");
        $this->assertEquals(404, $getResponse->getStatusCode());
    }

    public function testDeleteProductNotFound(): void
    {
        $response = $this->request('DELETE', '/api/products/99999');

        $this->assertEquals(404, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertEquals('Product not found', $body['error']);
    }

    public function testNotFoundRouteReturns404(): void
    {
        $response = $this->request('GET', '/api/nonexistent');

        $this->assertEquals(404, $response->getStatusCode());
    }
}
