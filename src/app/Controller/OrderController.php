<?php

declare(strict_types=1);

namespace App\Controller;

use App\Queue;
use PDO;
use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Order endpoints — preserves the original Router.php logic.
 * Will be refactored into Service/Repository layers in Milestone 2.
 */
class OrderController
{
    public function __construct(
        private readonly PDO $db,
        private readonly RedisClient $cache,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        $stmt = $this->db->query('SELECT * FROM orders ORDER BY id DESC');
        $response->getBody()->write(json_encode($stmt->fetchAll()));

        return $response;
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            $response->getBody()->write(json_encode(['error' => 'Order not found']));
            return $response->withStatus(404);
        }

        $stmt = $this->db->prepare(
            'SELECT oi.*, p.name as product_name FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        $response->getBody()->write(json_encode($order));

        return $response;
    }

    public function create(Request $request, Response $response): Response
    {
        $data = json_decode((string) $request->getBody(), true);

        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            $response->getBody()->write(json_encode(['error' => 'items array is required']));
            return $response->withStatus(400);
        }

        $this->db->beginTransaction();
        try {
            // Create order
            $this->db->prepare('INSERT INTO orders (total) VALUES (0)')->execute();
            $orderId = (int) $this->db->lastInsertId();

            $total = 0;
            $productIds = [];
            foreach ($data['items'] as $item) {
                if (!isset($item['product_id'], $item['quantity'])) {
                    throw new \InvalidArgumentException('Each item needs product_id and quantity');
                }

                if ((int) $item['quantity'] < 1) {
                    throw new \InvalidArgumentException('Quantity must be at least 1');
                }

                // Get product and check stock
                $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
                $stmt->execute([$item['product_id']]);
                $product = $stmt->fetch();

                if (!$product) {
                    throw new \InvalidArgumentException("Product {$item['product_id']} not found");
                }
                if ($product['stock'] < $item['quantity']) {
                    throw new \InvalidArgumentException("Insufficient stock for {$product['name']}");
                }

                // Add order item
                $lineTotal = (float) $product['price'] * (int) $item['quantity'];
                $stmt = $this->db->prepare(
                    'INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)'
                );
                $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $product['price']]);

                // Decrease stock
                $this->db->prepare('UPDATE products SET stock = stock - ? WHERE id = ?')
                    ->execute([$item['quantity'], $item['product_id']]);

                $productIds[] = $item['product_id'];
                $total += $lineTotal;
            }

            // Update order total
            $this->db->prepare('UPDATE orders SET total = ? WHERE id = ?')
                ->execute([$total, $orderId]);

            $this->db->commit();

            // Invalidate product caches
            foreach ($productIds as $pid) {
                $this->cache->del("products:{$pid}");
            }
            $this->cache->del('products:all');

            // Publish order event to RabbitMQ
            Queue::publish('order_created', [
                'order_id' => $orderId,
                'total' => $total,
                'created_at' => date('c'),
            ]);

            // Fetch the created order
            $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            $stmt = $this->db->prepare(
                'SELECT oi.*, p.name as product_name FROM order_items oi
                 JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = ?'
            );
            $stmt->execute([$orderId]);
            $order['items'] = $stmt->fetchAll();

            $response->getBody()->write(json_encode($order));

            return $response->withStatus(201);
        } catch (\InvalidArgumentException $e) {
            $this->db->rollBack();
            $response->getBody()->write(json_encode(['error' => $e->getMessage()]));
            return $response->withStatus(400);
        } catch (\Exception $e) {
            $this->db->rollBack();
            $response->getBody()->write(json_encode(['error' => 'Order creation failed']));
            return $response->withStatus(500);
        }
    }
}
