<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class ErrorHandler implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            $response = new Response();
            $response->getBody()->write(
                json_encode(['error' => 'Internal server error']),
            );

            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}
