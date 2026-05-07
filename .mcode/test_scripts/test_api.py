"""
Functional tests for the modernized PHP ecommerce API.
Tests all product CRUD endpoints, health check, and order endpoints.
Runs against the Docker Compose environment on port 8081.
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


# --- Health ---

class TestHealth:
    def test_health_endpoint(self):
        resp = requests.get(f"{BASE_URL}/health")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] in ("ok", "degraded")
        assert "services" in data
        assert "mysql" in data["services"]
        assert "redis" in data["services"]
        assert "rabbitmq" in data["services"]

    def test_health_mysql_connected(self):
        resp = requests.get(f"{BASE_URL}/health")
        data = resp.json()
        assert data["services"]["mysql"] == "connected"

    def test_health_redis_connected(self):
        resp = requests.get(f"{BASE_URL}/health")
        data = resp.json()
        assert data["services"]["redis"] == "connected"

    def test_health_rabbitmq_connected(self):
        resp = requests.get(f"{BASE_URL}/health")
        data = resp.json()
        assert data["services"]["rabbitmq"] == "connected"


# --- Products CRUD ---

class TestProductsList:
    def test_list_products(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        assert resp.status_code == 200
        data = resp.json()
        assert isinstance(data, list)
        # Seed data has 5 products
        assert len(data) >= 5

    def test_list_products_has_expected_fields(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        data = resp.json()
        product = data[0]
        assert "id" in product
        assert "name" in product
        assert "description" in product
        assert "price" in product
        assert "stock" in product
        assert "created_at" in product
        assert "updated_at" in product

    def test_list_products_content_type(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        assert "application/json" in resp.headers.get("Content-Type", "")


class TestProductGet:
    def test_get_product_by_id(self):
        resp = requests.get(f"{BASE_URL}/api/products/1")
        assert resp.status_code == 200
        data = resp.json()
        assert data["id"] == 1
        assert "name" in data

    def test_get_product_not_found(self):
        resp = requests.get(f"{BASE_URL}/api/products/99999")
        assert resp.status_code == 404
        data = resp.json()
        assert "error" in data
        assert "not found" in data["error"].lower()


class TestProductCreate:
    def test_create_product(self):
        payload = {
            "name": "Test Widget",
            "description": "A test product",
            "price": 15.99,
            "stock": 25,
        }
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 201
        data = resp.json()
        assert data["name"] == "Test Widget"
        assert data["description"] == "A test product"
        assert float(data["price"]) == 15.99
        assert data["stock"] == 25
        assert "id" in data

    def test_create_product_minimal(self):
        """Only name and price are required."""
        payload = {"name": "Minimal Product", "price": 5.00}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 201
        data = resp.json()
        assert data["name"] == "Minimal Product"
        assert data["stock"] == 0  # default

    def test_create_product_missing_name(self):
        payload = {"price": 10.00}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 400
        data = resp.json()
        assert "error" in data

    def test_create_product_missing_price(self):
        payload = {"name": "No Price"}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 400

    def test_create_product_negative_price(self):
        payload = {"name": "Bad Price", "price": -5.00}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 400

    def test_create_product_zero_price(self):
        payload = {"name": "Free Item", "price": 0}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 400

    def test_create_product_negative_stock(self):
        payload = {"name": "Bad Stock", "price": 10.00, "stock": -1}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 400


class TestProductUpdate:
    def _create_product(self):
        payload = {"name": "Update Target", "price": 20.00, "stock": 10}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        return resp.json()["id"]

    def test_update_product_name(self):
        pid = self._create_product()
        resp = requests.put(f"{BASE_URL}/api/products/{pid}", json={"name": "Updated Name"})
        assert resp.status_code == 200
        data = resp.json()
        assert data["name"] == "Updated Name"

    def test_update_product_price(self):
        pid = self._create_product()
        resp = requests.put(f"{BASE_URL}/api/products/{pid}", json={"price": 99.99})
        assert resp.status_code == 200
        assert float(resp.json()["price"]) == 99.99

    def test_update_product_not_found(self):
        resp = requests.put(f"{BASE_URL}/api/products/99999", json={"name": "Ghost"})
        assert resp.status_code == 404

    def test_update_product_no_fields(self):
        pid = self._create_product()
        resp = requests.put(f"{BASE_URL}/api/products/{pid}", json={})
        assert resp.status_code == 400


class TestProductDelete:
    def _create_product(self):
        payload = {"name": "Delete Target", "price": 5.00, "stock": 1}
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        return resp.json()["id"]

    def test_delete_product(self):
        pid = self._create_product()
        resp = requests.delete(f"{BASE_URL}/api/products/{pid}")
        assert resp.status_code == 204

        # Confirm it's gone
        resp = requests.get(f"{BASE_URL}/api/products/{pid}")
        assert resp.status_code == 404

    def test_delete_product_not_found(self):
        resp = requests.delete(f"{BASE_URL}/api/products/99999")
        assert resp.status_code == 404


# --- Orders ---

class TestOrders:
    def test_list_orders(self):
        resp = requests.get(f"{BASE_URL}/api/orders")
        assert resp.status_code == 200
        data = resp.json()
        assert isinstance(data, list)

    def test_create_order(self):
        # Use seed product id=1 (Wireless Mouse, stock=150)
        payload = {
            "items": [
                {"product_id": 1, "quantity": 1}
            ]
        }
        resp = requests.post(f"{BASE_URL}/api/orders", json=payload)
        assert resp.status_code == 201
        data = resp.json()
        assert "id" in data
        assert "items" in data
        assert float(data["total"]) > 0

    def test_get_order(self):
        # Create an order first
        payload = {"items": [{"product_id": 2, "quantity": 1}]}
        create_resp = requests.post(f"{BASE_URL}/api/orders", json=payload)
        order_id = create_resp.json()["id"]

        resp = requests.get(f"{BASE_URL}/api/orders/{order_id}")
        assert resp.status_code == 200
        data = resp.json()
        assert data["id"] == order_id
        assert "items" in data
        assert len(data["items"]) >= 1

    def test_get_order_not_found(self):
        resp = requests.get(f"{BASE_URL}/api/orders/99999")
        assert resp.status_code == 404

    def test_create_order_missing_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400

    def test_create_order_empty_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400


# --- 404 for unknown routes ---

class TestNotFound:
    def test_unknown_route(self):
        resp = requests.get(f"{BASE_URL}/api/nonexistent")
        assert resp.status_code == 404
