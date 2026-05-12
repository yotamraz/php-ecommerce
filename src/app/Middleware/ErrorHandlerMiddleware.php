<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Catches exceptions and returns structured JSON error responses.
 */
class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Respect\Validation\Exceptions\NestedValidationException $e) {
            return $this->jsonResponse(400, [
                'error' => 'Validation failed',
                'details' => $e->getMessages(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonResponse(400, [
                'error' => $e->getMessage(),
            ]);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            // Use exception code as HTTP status if it's a valid 4xx code
            $status = ($code >= 400 && $code < 500) ? $code : 500;
            $message = $status < 500 ? $e->getMessage() : 'Internal server error';
            return $this->jsonResponse($status, [
                'error' => $message,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse(500, [
                'error' => 'Internal server error',
            ]);
        }
    }

    /**
     * Build a JSON error response.
     */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode($body));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
