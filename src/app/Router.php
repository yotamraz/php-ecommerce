<?php

namespace App;

use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\LockMode;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Router
{
    private EntityManager $em;
    private RedisClient $cache;
    private AMQPStreamConnection $queue;

    public function __construct(EntityManager $em, RedisClient $cache, AMQPStreamConnection $queue)
    {
        $this->em = $em;
        $this->cache = $cache;
        $this->queue = $queue;
    }

    public function handleRequest(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = rtrim($uri, '/');

        // Route matching
        switch (true) {
            // Health check
            case $uri === '/health' && $method === 'GET':
                $this->health();
                break;

            // Products
            case $uri === '/api/products' && $method === 'GET':
                $this->listProducts();
                break;
            case preg_match('#^/api/products/(\d+)$#', $uri, $m) && $method === 'GET':
                $this->getProduct((int) $m[1]);
                break;
            case $uri === '/api/products' && $method === 'POST':
                $this->createProduct();
                break;
            case preg_match('#^/api/products/(\d+)$#', $uri, $m) && $method === 'PUT':
                $this->updateProduct((int) $m[1]);
                break;
            case preg_match('#^/api/products/(\d+)$#', $uri, $m) && $method === 'DELETE':
                $this->deleteProduct((int) $m[1]);
                break;

            // Orders
            case $uri === '/api/orders' && $method === 'GET':
                $this->listOrders();
                break;
            case preg_match('#^/api/orders/(\d+)$#', $uri, $m) && $method === 'GET':
                $this->getOrder((int) $m[1]);
                break;
            case $uri === '/api/orders' && $method === 'POST':
                $this->createOrder();
                break;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Not found']);
        }
    }

    // --- Health ---

    private function health(): void
    {
        $status = ['status' => 'ok', 'services' => []];

        // MySQL
        try {
            $this->em->getConnection()->executeQuery('SELECT 1');
            $status['services']['mysql'] = 'connected';
        } catch (\Exception $e) {
            $status['services']['mysql'] = 'error';
            $status['status'] = 'degraded';
        }

        // Redis
        try {
            $this->cache->ping();
            $status['services']['redis'] = 'connected';
        } catch (\Exception $e) {
            $status['services']['redis'] = 'error';
            $status['status'] = 'degraded';
        }

        // RabbitMQ
        try {
            $this->queue->isConnected()
                ? $status['services']['rabbitmq'] = 'connected'
                : $status['services']['rabbitmq'] = 'error';
        } catch (\Exception $e) {
            $status['services']['rabbitmq'] = 'error';
            $status['status'] = 'degraded';
        }

        echo json_encode($status);
    }

    // --- Products ---

    private function listProducts(): void
    {
        // Try cache first
        $cached = $this->cache->get('products:all');
        if ($cached) {
            echo $cached;
            return;
        }

        /** @var \App\Repository\ProductRepository $repo */
        $repo = $this->em->getRepository(\App\Entity\Product::class);
        $products = $repo->findAllOrdered();

        $json = json_encode(array_map([$this, 'serializeProduct'], $products));
        $this->cache->setex('products:all', 60, $json);
        echo $json;
    }

    private function getProduct(int $id): void
    {
        $cached = $this->cache->get("products:{$id}");
        if ($cached) {
            echo $cached;
            return;
        }

        $product = $this->em->find(\App\Entity\Product::class, $id);

        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        $json = json_encode($this->serializeProduct($product));
        $this->cache->setex("products:{$id}", 60, $json);
        echo $json;
    }

    private function createProduct(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['name'], $data['price'])) {
            http_response_code(400);
            echo json_encode(['error' => 'name and price are required']);
            return;
        }

        if ((float) $data['price'] <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'price must be greater than zero']);
            return;
        }

        if (isset($data['stock']) && (int) $data['stock'] < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'stock cannot be negative']);
            return;
        }

        $product = new \App\Entity\Product();
        $product->setName($data['name']);
        $product->setDescription($data['description'] ?? null);
        $product->setPrice((string) $data['price']);
        $product->setStock((int) ($data['stock'] ?? 0));
        $this->em->persist($product);
        $this->em->flush();
        $this->em->refresh($product);
        $id = $product->getId();

        $this->cache->del('products:all');

        http_response_code(201);
        $this->getProduct($id);
    }

    private function updateProduct(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $product = $this->em->find(\App\Entity\Product::class, $id);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        if (empty($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        if (isset($data['name']))        $product->setName($data['name']);
        if (isset($data['description'])) $product->setDescription($data['description']);
        if (isset($data['price']))       $product->setPrice((string) $data['price']);
        if (isset($data['stock']))       $product->setStock((int) $data['stock']);
        $this->em->flush();
        $this->em->refresh($product);

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        $this->getProduct($id);
    }

    private function deleteProduct(int $id): void
    {
        $product = $this->em->find(\App\Entity\Product::class, $id);
        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found']);
            return;
        }

        try {
            $this->em->remove($product);
            $this->em->flush();
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot delete product that is referenced by orders']);
            return;
        }

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        http_response_code(204);
    }

    // --- Orders ---

    private function listOrders(): void
    {
        $orders = $this->em->getRepository(\App\Entity\Order::class)->findBy([], ['id' => 'DESC']);
        echo json_encode(array_map([$this, 'serializeOrder'], $orders));
    }

    private function getOrder(int $id): void
    {
        /** @var \App\Repository\OrderRepository $repo */
        $repo = $this->em->getRepository(\App\Entity\Order::class);
        $order = $repo->findWithItems($id);

        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            return;
        }

        echo json_encode($this->serializeOrder($order));
    }

    private function createOrder(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            http_response_code(400);
            echo json_encode(['error' => 'items array is required']);
            return;
        }

        $this->em->beginTransaction();
        try {
            // Create order
            $order = new \App\Entity\Order();
            $this->em->persist($order);
            $this->em->flush(); // flush to get order id for FK

            $total = 0.0;
            $productIds = [];
            foreach ($data['items'] as $item) {
                if (!isset($item['product_id'], $item['quantity'])) {
                    throw new \InvalidArgumentException('Each item needs product_id and quantity');
                }
                if ((int) $item['quantity'] < 1) {
                    throw new \InvalidArgumentException('Quantity must be at least 1');
                }

                // Lock product row to prevent overselling
                $product = $this->em->find(
                    \App\Entity\Product::class,
                    $item['product_id'],
                    LockMode::PESSIMISTIC_WRITE
                );

                if (!$product) {
                    throw new \InvalidArgumentException("Product {$item['product_id']} not found");
                }
                if ($product->getStock() < (int) $item['quantity']) {
                    throw new \InvalidArgumentException("Insufficient stock for {$product->getName()}");
                }

                $lineTotal = (float) $product->getPrice() * (int) $item['quantity'];

                $orderItem = new \App\Entity\OrderItem();
                $orderItem->setOrder($order);
                $orderItem->setProduct($product);
                $orderItem->setQuantity((int) $item['quantity']);
                $orderItem->setPrice($product->getPrice());
                $this->em->persist($orderItem);

                $product->setStock($product->getStock() - (int) $item['quantity']);

                $productIds[] = $item['product_id'];
                $total += $lineTotal;
            }

            $order->setTotal(number_format($total, 2, '.', ''));
            $this->em->flush();
            $this->em->commit();

            // Clear identity map so getOrder reads fresh data
            $this->em->clear();

            // Invalidate product caches
            foreach ($productIds as $pid) {
                $this->cache->del("products:{$pid}");
            }
            $this->cache->del('products:all');

            // Publish order event to RabbitMQ
            Queue::publish('order_created', [
                'order_id' => $order->getId(),
                'total'    => $total,
                'created_at' => date('c'),
            ]);

            http_response_code(201);
            $this->getOrder($order->getId());
        } catch (\InvalidArgumentException $e) {
            $this->em->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->em->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Order creation failed']);
        }
    }

    // --- Serialization helpers ---

    private function serializeProduct(\App\Entity\Product $p): array
    {
        return [
            'id'          => $p->getId(),
            'name'        => $p->getName(),
            'description' => $p->getDescription(),
            'price'       => $p->getPrice(),
            'stock'       => $p->getStock(),
            'created_at'  => $p->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at'  => $p->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    private function serializeOrder(\App\Entity\Order $o): array
    {
        $items = [];
        foreach ($o->getItems() as $item) {
            $items[] = [
                'id'           => $item->getId(),
                'order_id'     => $o->getId(),
                'product_id'   => $item->getProduct()->getId(),
                'product_name' => $item->getProduct()->getName(),
                'quantity'     => $item->getQuantity(),
                'price'        => $item->getPrice(),
            ];
        }
        return [
            'id'         => $o->getId(),
            'status'     => $o->getStatus(),
            'total'      => $o->getTotal(),
            'created_at' => $o->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $o->getUpdatedAt()->format('Y-m-d H:i:s'),
            'items'      => $items,
        ];
    }
}
