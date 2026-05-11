<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Catches exceptions and returns structured JSON error responses.
 *
 * Validation errors include a "details" field for structured error information.
 * Non-validation errors use the simple {"error": "message"} format.
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
            // Respect/Validation structured errors
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => 'Validation failed',
                'details' => $e->getMessages(),
            ]));
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        } catch (ValidationException $e) {
            // Domain validation errors with optional details
            $response = new Response();
            $body = ['error' => $e->getMessage()];
            $details = $e->getDetails();
            if (!empty($details)) {
                $body['details'] = $details;
            }
            $response->getBody()->write(json_encode($body));
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        } catch (\InvalidArgumentException $e) {
            // Simple validation / input errors
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage(),
            ]));
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => 'Internal server error',
            ]));
            return $response
                ->withStatus(500)
                ->withHeader('Content-Type', 'application/json');
        }
    }
}
