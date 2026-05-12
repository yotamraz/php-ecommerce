<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProductService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * HTTP controller for product endpoints.
 * Handles PSR-7 request/response and delegates to ProductService.
 */
class ProductController
{
    public function __construct(
        private ProductService $productService,
    ) {}

    /**
     * GET /api/products
     */
    public function list(Request $request, Response $response): Response
    {
        $products = $this->productService->listProducts();
        $response->getBody()->write(json_encode($products));
        return $response;
    }

    /**
     * GET /api/products/{id}
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $product = $this->productService->getProduct($id);

        if ($product === null) {
            $response->getBody()->write(json_encode(['error' => 'Product not found']));
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode($product));
        return $response;
    }

    /**
     * POST /api/products
     */
    public function create(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();

        try {
            $product = $this->productService->createProduct($data ?? []);
            $response->getBody()->write(json_encode($product));
            return $response->withStatus(201);
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400);
        }
    }

    /**
     * PUT /api/products/{id}
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = $request->getParsedBody();

        try {
            $product = $this->productService->updateProduct($id, $data ?? []);
            $response->getBody()->write(json_encode($product));
            return $response;
        } catch (\InvalidArgumentException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400);
        } catch (\RuntimeException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus($e->getCode());
        }
    }

    /**
     * DELETE /api/products/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        try {
            $this->productService->deleteProduct($id);
            return $response->withStatus(204);
        } catch (\RuntimeException $e) {
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus($e->getCode());
        }
    }
}
