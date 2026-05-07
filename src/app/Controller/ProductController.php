<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Service\ProductService;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProductController
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $products = $this->productService->listAll();
        $data = array_map(fn(Product $p) => $p->toArray(), $products);

        $response->getBody()->write(json_encode($data));

        return $response;
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $product = $this->productService->getById($id);

        if ($product === null) {
            $response->getBody()->write(json_encode(['error' => 'Product not found']));
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode($product->toArray()));

        return $response;
    }

    public function create(Request $request, Response $response): Response
    {
        $data = json_decode((string) $request->getBody(), true);

        if (!isset($data['name'], $data['price'])) {
            $response->getBody()->write(json_encode(['error' => 'name and price are required']));
            return $response->withStatus(400);
        }

        if ((float) $data['price'] <= 0) {
            $response->getBody()->write(json_encode(['error' => 'price must be greater than zero']));
            return $response->withStatus(400);
        }

        if (isset($data['stock']) && (int) $data['stock'] < 0) {
            $response->getBody()->write(json_encode(['error' => 'stock cannot be negative']));
            return $response->withStatus(400);
        }

        $product = $this->productService->create(
            name: $data['name'],
            description: $data['description'] ?? '',
            price: (float) $data['price'],
            stock: (int) ($data['stock'] ?? 0),
        );

        $response->getBody()->write(json_encode($product->toArray()));

        return $response->withStatus(201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = json_decode((string) $request->getBody(), true);

        $fields = [];
        foreach (['name', 'description', 'price', 'stock'] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            $response->getBody()->write(json_encode(['error' => 'No fields to update']));
            return $response->withStatus(400);
        }

        $product = $this->productService->update($id, $fields);

        if ($product === null) {
            $response->getBody()->write(json_encode(['error' => 'Product not found']));
            return $response->withStatus(404);
        }

        $response->getBody()->write(json_encode($product->toArray()));

        return $response;
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        try {
            $rowCount = $this->productService->delete($id);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $response->getBody()->write(
                    json_encode(['error' => 'Cannot delete product that is referenced by orders']),
                );
                return $response->withStatus(409);
            }
            throw $e;
        }

        if ($rowCount === 0) {
            $response->getBody()->write(json_encode(['error' => 'Product not found']));
            return $response->withStatus(404);
        }

        return $response->withStatus(204);
    }
}
