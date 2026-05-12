<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP controller for order endpoints.
 * Handles PSR-7 request/response and delegates to OrderService.
 */
class OrderController
{
    public function __construct(
        private OrderService $orderService,
    ) {}

    /**
     * GET /api/orders
     */
    public function list(Request $request, Response $response): Response
    {
        $orders = $this->orderService->listOrders();
        $response->getBody()->write(json_encode($orders));
        return $response;
    }

    /**
     * GET /api/orders/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $order = $this->orderService->getOrder($id);

        if ($order === null) {
            $response->getBody()->write(json_encode(['error' => 'Order not found']));
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode($order));
        return $response;
    }

    /**
     * POST /api/orders
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        try {
            $order = $this->orderService->createOrder($data ?? []);
            $response->getBody()->write(json_encode($order));
            return $response->withStatus(201);
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400);
        } catch (\RuntimeException $e) {
            $code = $e->getCode();
            $status = in_array($code, [400, 404, 409], true) ? $code : 500;
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus($status);
        }
    }
}
