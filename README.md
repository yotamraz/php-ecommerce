# PHP Ecommerce Sandbox

A simple PHP REST API for ecommerce, backed by MySQL, Redis, and RabbitMQ. Runs entirely via Docker Compose.

## Architecture

```
Client  -->  Nginx (:8080)  -->  PHP-FPM  -->  MySQL (data)
                                           -->  Redis (cache)
                                           -->  RabbitMQ (events)
```

- **Nginx** reverse-proxies to PHP-FPM
- **MySQL 8.0** stores products, orders, and order items
- **Redis 7** caches product listings and individual products (60s TTL)
- **RabbitMQ 3** receives order-created events for async processing

Services start in dependency order via health checks: MySQL, Redis, and RabbitMQ must be healthy before the PHP container starts.

## Prerequisites

- Docker and Docker Compose

## Quick Start

```bash
./run.sh          # build and start all services
./run.sh down     # stop all services
./run.sh reset    # stop and remove all data
./run.sh logs     # tail all logs
./run.sh logs php # tail PHP logs only
```

Wait for `ready to handle connections` in the PHP logs before sending requests:

```bash
docker logs -f ecommerce-php
```

## API Endpoints

Collection endpoints (`GET /api/products`, `/api/orders`, `/api/campaigns`, `/api/campaigns/active`) return `404` when no matching rows exist.

### Health

```
GET /health
```

Returns connection status for all backing services.

### Products

```
GET    /api/products          # list all (cached 60s)
GET    /api/products/:id      # get one (cached 60s)
POST   /api/products          # create
PUT    /api/products/:id      # update
DELETE /api/products/:id      # delete
```

**Create / Update body:**

```json
{
  "name": "Widget",
  "description": "A fine widget",
  "price": 19.99,
  "stock": 100
}
```

### Orders

```
GET  /api/orders          # list all
GET  /api/orders/:id      # get one (includes line items)
POST /api/orders          # create
```

**Create body:**

```json
{
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 3, "quantity": 1 }
  ]
}
```

Order creation validates stock, decrements inventory, calculates totals, and publishes an `order_created` event to RabbitMQ.

**Apply a coupon** by adding `coupon_code` to the order body:

```json
{
  "items": [{ "product_id": 1, "quantity": 2 }],
  "coupon_code": "LAUNCH10"
}
```

The campaign discount is applied to the total, and `campaign_id` / `discount_amount` are recorded on the order.

### Campaigns

Pricing campaigns: `percent_off` or `fixed_off`, with an optional coupon code, date window, status, and usage limit.

```
GET    /api/campaigns            # list all (optional ?status=active)
GET    /api/campaigns/active     # active + within date window
GET    /api/campaigns/:id        # get one
POST   /api/campaigns            # create
PUT    /api/campaigns/:id        # update fields / status
DELETE /api/campaigns/:id        # delete (409 if referenced by orders)
POST   /api/campaigns/validate   # check a coupon code
```

**Create / Update body:**

```json
{
  "name": "Launch 10% Off",
  "description": "10% off any order",
  "type": "percent_off",
  "value": 10,
  "coupon_code": "LAUNCH10",
  "starts_at": "2020-01-01 00:00:00",
  "ends_at": "2030-01-01 00:00:00",
  "status": "active",
  "usage_limit": null
}
```

`type` is `percent_off` (value = 0-100) or `fixed_off` (value = amount). `status` is one of `draft`, `active`, `paused`, `ended`. A campaign is applied only when `active`, within its date window, and under its `usage_limit`.

**Validate body:**

```json
{ "coupon_code": "LAUNCH10" }
```

Returns `{ "valid": true, "campaign": { ... } }`, or `404` with `{ "valid": false }` if not usable.

## Example Session

The database starts empty. Example rows live in [`data/`](data/) as CSV (`products.csv`, `campaigns.csv`); collection endpoints return `404` until you create data.

```bash
# Check services
curl http://localhost:8080/health

# Empty DB -> 404
curl -i http://localhost:8080/api/products

# Create a product (values from data/products.csv)
curl -X POST http://localhost:8080/api/products \
  -H 'Content-Type: application/json' \
  -d '{"name":"Wireless Mouse","description":"Ergonomic wireless mouse with USB receiver","price":29.99,"stock":150}'

# Create an active coupon campaign (values from data/campaigns.csv)
curl -X POST http://localhost:8080/api/campaigns \
  -H 'Content-Type: application/json' \
  -d '{"name":"Launch 10% Off","type":"percent_off","value":10,"coupon_code":"LAUNCH10","status":"active"}'

# Place a discounted order
curl -X POST http://localhost:8080/api/orders \
  -H 'Content-Type: application/json' \
  -d '{"items":[{"product_id":1,"quantity":2}],"coupon_code":"LAUNCH10"}'
```

## Project Structure

```
.
├── docker-compose.yml      # service definitions
├── Dockerfile              # PHP 8.2-FPM + extensions
├── entrypoint.sh           # composer install at startup
├── run.sh                  # convenience shell runner
├── nginx/default.conf      # nginx -> php-fpm proxy
├── db/init.sql             # schema only (no seed data)
├── data/                   # example rows as CSV (not auto-loaded)
└── src/
    ├── composer.json
    ├── public/index.php    # entry point
    └── app/
        ├── Database.php        # PDO/MySQL connection
        ├── Cache.php           # Redis (Predis) connection
        ├── Queue.php           # RabbitMQ publisher
        ├── CampaignService.php # Campaign CRUD + discount logic
        └── Router.php          # REST API routing + handlers
```

## Ports

| Service           | Port  |
| ----------------- | ----- |
| API (Nginx)       | 8080  |
| MySQL             | 3306  |
| Redis             | 6379  |
| RabbitMQ          | 5672  |
| RabbitMQ Management | 15672 |

RabbitMQ Management UI: http://localhost:15672 (guest / guest)

## License

MIT
