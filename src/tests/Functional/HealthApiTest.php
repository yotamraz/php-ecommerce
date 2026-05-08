<?php

declare(strict_types=1);

namespace Tests\Functional;

/**
 * Functional tests for the GET /health endpoint.
 * Requires MySQL, Redis, and RabbitMQ to be running.
 */
class HealthApiTest extends TestCase
{
    public function testHealthEndpointReturns200(): void
    {
        $response = $this->request('GET', '/health');

        $this->assertEquals(200, $response->getStatusCode());

        $body = $this->getResponseBody($response);
        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('services', $body);
        $this->assertArrayHasKey('mysql', $body['services']);
        $this->assertArrayHasKey('redis', $body['services']);
        $this->assertArrayHasKey('rabbitmq', $body['services']);
    }

    public function testHealthEndpointHasJsonContentType(): void
    {
        $response = $this->request('GET', '/health');

        $this->assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type')
        );
    }
}
