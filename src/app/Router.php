<?php

namespace App;

use PDO;
use Predis\Client as RedisClient;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class Router
{
    private PDO $db;
    private RedisClient $cache;
    private AMQPStreamConnection $queue;
    private CampaignService $campaigns;

    public function __construct(PDO $db, RedisClient $cache, AMQPStreamConnection $queue)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->queue = $queue;
        $this->campaigns = new CampaignService($db);
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

            // Campaigns
            case $uri === '/api/campaigns' && $method === 'GET':
                $this->listCampaigns();
                break;
            case $uri === '/api/campaigns/active' && $method === 'GET':
                $this->listActiveCampaigns();
                break;
            case $uri === '/api/campaigns/validate' && $method === 'POST':
                $this->validateCoupon();
                break;
            case $uri === '/api/campaigns' && $method === 'POST':
                $this->createCampaign();
                break;
            case preg_match('#^/api/campaigns/(\d+)$#', $uri, $m) && $method === 'GET':
                $this->getCampaign((int) $m[1]);
                break;
            case preg_match('#^/api/campaigns/(\d+)$#', $uri, $m) && $method === 'PUT':
                $this->updateCampaign((int) $m[1]);
                break;
            case preg_match('#^/api/campaigns/(\d+)$#', $uri, $m) && $method === 'DELETE':
                $this->deleteCampaign((int) $m[1]);
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
            $this->db->query('SELECT 1');
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

        $stmt = $this->db->query('SELECT * FROM products ORDER BY id');
        $products = $stmt->fetchAll();

        if (empty($products)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $json = json_encode($products);
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

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $json = json_encode($product);
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

        $stmt = $this->db->prepare(
            'INSERT INTO products (name, description, price, stock) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['name'],
            $data['description'] ?? '',
            (float) $data['price'],
            (int) ($data['stock'] ?? 0),
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->cache->del('products:all');

        http_response_code(201);
        $this->getProduct($id);
    }

    private function updateProduct(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        $stmt = $this->db->prepare('SELECT id FROM products WHERE id = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $fields = [];
        $values = [];
        foreach (['name', 'description', 'price', 'stock'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            return;
        }

        $values[] = $id;
        $sql = 'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($values);

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        $this->getProduct($id);
    }

    private function deleteProduct(int $id): void
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$id]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'Cannot delete product that is referenced by orders']);
                return;
            }
            throw $e;
        }

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $this->cache->del("products:{$id}");
        $this->cache->del('products:all');

        http_response_code(204);
    }

    // --- Orders ---

    private function listOrders(): void
    {
        $orders = $this->db->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll();

        if (empty($orders)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        echo json_encode($orders);
    }

    private function getOrder(int $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT oi.*, p.name as product_name FROM order_items oi
             JOIN products p ON p.id = oi.product_id
             WHERE oi.order_id = ?'
        );
        $stmt->execute([$id]);
        $order['items'] = $stmt->fetchAll();

        echo json_encode($order);
    }

    private function createOrder(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            http_response_code(400);
            echo json_encode(['error' => 'items array is required']);
            return;
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

            // Apply coupon campaign, if supplied
            $campaignId = null;
            $discount = 0.0;
            if (!empty($data['coupon_code'])) {
                $campaign = $this->campaigns->findByCoupon((string) $data['coupon_code']);
                if (!$campaign || !$this->campaigns->isRunnable($campaign)) {
                    throw new \InvalidArgumentException('Invalid or inactive coupon code');
                }
                $discount = $this->campaigns->computeDiscount((float) $total, $campaign);
                $campaignId = (int) $campaign['id'];
                $this->campaigns->incrementUsage($campaignId);
            }

            $finalTotal = round((float) $total - $discount, 2);

            // Update order total + applied campaign
            $this->db->prepare(
                'UPDATE orders SET total = ?, campaign_id = ?, discount_amount = ? WHERE id = ?'
            )->execute([$finalTotal, $campaignId, $discount, $orderId]);

            $this->db->commit();

            // Invalidate product caches
            foreach ($productIds as $pid) {
                $this->cache->del("products:{$pid}");
            }
            $this->cache->del('products:all');

            // Publish order event to RabbitMQ
            Queue::publish('order_created', [
                'order_id' => $orderId,
                'total' => $finalTotal,
                'discount_amount' => $discount,
                'campaign_id' => $campaignId,
                'created_at' => date('c'),
            ]);

            http_response_code(201);
            $this->getOrder($orderId);
        } catch (\InvalidArgumentException $e) {
            $this->db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            http_response_code(500);
            echo json_encode(['error' => 'Order creation failed']);
        }
    }

    // --- Campaigns ---

    private function listCampaigns(): void
    {
        $status = $_GET['status'] ?? null;
        $campaigns = $this->campaigns->all($status !== null ? (string) $status : null);

        if (empty($campaigns)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        echo json_encode($campaigns);
    }

    private function listActiveCampaigns(): void
    {
        $campaigns = $this->campaigns->active();

        if (empty($campaigns)) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }

        echo json_encode($campaigns);
    }

    private function getCampaign(int $id): void
    {
        $campaign = $this->campaigns->find($id);
        if (!$campaign) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }
        echo json_encode($campaign);
    }

    private function createCampaign(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            [$campaign, $error] = $this->campaigns->create($data);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'coupon_code already exists']);
                return;
            }
            throw $e;
        }

        if ($error !== null) {
            http_response_code(400);
            echo json_encode(['error' => $error]);
            return;
        }

        http_response_code(201);
        echo json_encode($campaign);
    }

    private function updateCampaign(int $id): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        try {
            [$campaign, $error] = $this->campaigns->update($id, $data);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(['error' => 'coupon_code already exists']);
                return;
            }
            throw $e;
        }

        if ($error === 'not_found') {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }
        if ($error !== null) {
            http_response_code(400);
            echo json_encode(['error' => $error === 'no_fields' ? 'No fields to update' : $error]);
            return;
        }

        echo json_encode($campaign);
    }

    private function deleteCampaign(int $id): void
    {
        $error = $this->campaigns->delete($id);

        if ($error === 'not_found') {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            return;
        }
        if ($error === 'referenced') {
            http_response_code(409);
            echo json_encode(['error' => 'Cannot delete campaign that is referenced by orders']);
            return;
        }

        http_response_code(204);
    }

    // Check a coupon code without placing an order.
    private function validateCoupon(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        if (empty($data['coupon_code'])) {
            http_response_code(400);
            echo json_encode(['error' => 'coupon_code is required']);
            return;
        }

        $campaign = $this->campaigns->findByCoupon((string) $data['coupon_code']);
        if (!$campaign || !$this->campaigns->isRunnable($campaign)) {
            http_response_code(404);
            echo json_encode(['valid' => false, 'error' => 'Invalid or inactive coupon code']);
            return;
        }

        echo json_encode(['valid' => true, 'campaign' => $campaign]);
    }
}
