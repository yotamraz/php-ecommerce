<?php

declare(strict_types=1);

namespace Tests\Functional;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\JsonResponseMiddleware;

/**
 * Base test case for functional tests that exercises the full Slim app pipeline.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->createApp();
    }

    protected function createApp(): App
    {
        $containerBuilder = new ContainerBuilder();
        $containerDefinitions = require __DIR__ . '/../../config/container.php';
        $containerDefinitions($containerBuilder);
        $container = $containerBuilder->build();

        $app = AppFactory::createFromContainer($container);

        // Add middleware in same order as production
        $app->addRoutingMiddleware();
        $app->add(new JsonResponseMiddleware());
        $app->add(new ErrorHandlerMiddleware());

        // Register routes
        $routes = require __DIR__ . '/../../app/routes.php';
        $routes($app);

        return $app;
    }

    /**
     * Create and handle a request through the app.
     */
    protected function request(
        string $method,
        string $uri,
        ?array $body = null,
    ): ResponseInterface {
        $request = $this->createRequest($method, $uri, $body);
        return $this->app->handle($request);
    }

    /**
     * Create a PSR-7 server request.
     */
    protected function createRequest(
        string $method,
        string $uri,
        ?array $body = null,
    ): ServerRequestInterface {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, $uri);

        if ($body !== null) {
            $streamFactory = new StreamFactory();
            $stream = $streamFactory->createStream(json_encode($body));
            $request = $request
                ->withBody($stream)
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        }

        return $request;
    }

    /**
     * Decode JSON response body.
     */
    protected function getResponseBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        return json_decode($body, true) ?? [];
    }
}
