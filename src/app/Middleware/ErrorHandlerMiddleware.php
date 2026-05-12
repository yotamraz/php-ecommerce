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
 * Exception handling order matters:
 * 1. ValidationException — structured error with details
 * 2. InvalidArgumentException — simple error message (400)
 * 3. RuntimeException — uses exception code as status (404, 409, etc.)
 * 4. Throwable — generic 500
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationException $e) {
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
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage(),
            ]));
            return $response
                ->withStatus(400)
                ->withHeader('Content-Type', 'application/json');
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $statusCode = ($code >= 400 && $code < 600) ? $code : 500;
            $response = new Response();
            $response->getBody()->write(json_encode([
                'error' => $e->getMessage(),
            ]));
            return $response
                ->withStatus($statusCode)
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
