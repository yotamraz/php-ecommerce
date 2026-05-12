"""
Functional tests for the modernized PHP Ecommerce Product API.
Tests all product CRUD endpoints and health check against a running instance.
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


class TestHealthEndpoint:
    def test_health_returns_200(self):
        resp = requests.get(f"{BASE_URL}/health")
        assert resp.status_code == 200, f"Expected 200, got {resp.status_code}: {resp.text}"

    def test_health_has_correct_structure(self):
        resp = requests.get(f"{BASE_URL}/health")
        data = resp.json()
        assert "status" in data, f"Missing 'status' key in response: {data}"
        assert "services" in data, f"Missing 'services' key in response: {data}"
        assert "mysql" in data["services"], f"Missing 'mysql' in services: {data}"
        assert "redis" in data["services"], f"Missing 'redis' in services: {data}"
        assert "rabbitmq" in data["services"], f"Missing 'rabbitmq' in services: {data}"

    def test_health_json_content_type(self):
        resp = requests.get(f"{BASE_URL}/health")
        assert "application/json" in resp.headers.get("Content-Type", ""), \
            f"Expected application/json, got {resp.headers.get('Content-Type')}"


class TestListProducts:
    def test_list_products_returns_200(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        assert resp.status_code == 200

    def test_list_products_returns_array(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        data = resp.json()
        assert isinstance(data, list), f"Expected list, got {type(data)}"

    def test_list_products_json_content_type(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        assert "application/json" in resp.headers.get("Content-Type", "")


class TestGetProduct:
    def test_get_nonexistent_product_returns_404(self):
        resp = requests.get(f"{BASE_URL}/api/products/99999")
        assert resp.status_code == 404
        data = resp.json()
        assert data["error"] == "Product not found"


class TestCreateProduct:
    def test_create_product_success(self):
        payload = {
            "name": "Pytest Test Product",
            "price": 19.99,
            "stock": 50,
            "description": "Created by pytest"
        }
        resp = requests.post(f"{BASE_URL}/api/products", json=payload)
        assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert data["name"] == "Pytest Test Product"
        assert float(data["price"]) == 19.99
        assert int(data["stock"]) == 50
        assert "id" in data

    def test_create_product_missing_required_fields(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "name and price are required"

    def test_create_product_negative_price(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Bad Product",
            "price": -5
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "price must be greater than zero"

    def test_create_product_zero_price(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Zero Price Product",
            "price": 0
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "price must be greater than zero"

    def test_create_product_negative_stock(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Bad Stock Product",
            "price": 10,
            "stock": -1
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "stock cannot be negative"

    def test_create_product_default_stock(self):
        """Stock defaults to 0 when not provided"""
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "No Stock Product",
            "price": 15.00
        })
        assert resp.status_code == 201
        data = resp.json()
        assert int(data["stock"]) == 0


class TestUpdateProduct:
    def _create_product(self, name="Update Test Product", price=10.00, stock=5):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": name, "price": price, "stock": stock
        })
        assert resp.status_code == 201
        return resp.json()

    def test_update_product_success(self):
        product = self._create_product()
        pid = product["id"]

        resp = requests.put(f"{BASE_URL}/api/products/{pid}", json={
            "name": "Updated Name",
            "price": 25.00
        })
        assert resp.status_code == 200
        data = resp.json()
        assert data["name"] == "Updated Name"
        assert float(data["price"]) == 25.00

    def test_update_product_not_found(self):
        resp = requests.put(f"{BASE_URL}/api/products/99999", json={
            "name": "Ghost"
        })
        assert resp.status_code == 404
        data = resp.json()
        assert data["error"] == "Product not found"

    def test_update_product_no_fields(self):
        product = self._create_product()
        pid = product["id"]

        resp = requests.put(f"{BASE_URL}/api/products/{pid}", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "No fields to update"


class TestDeleteProduct:
    def _create_product(self, name="Delete Test Product", price=5.00):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": name, "price": price
        })
        assert resp.status_code == 201
        return resp.json()

    def test_delete_product_success(self):
        product = self._create_product()
        pid = product["id"]

        resp = requests.delete(f"{BASE_URL}/api/products/{pid}")
        assert resp.status_code == 204

        # Verify it's gone
        get_resp = requests.get(f"{BASE_URL}/api/products/{pid}")
        assert get_resp.status_code == 404

    def test_delete_product_not_found(self):
        resp = requests.delete(f"{BASE_URL}/api/products/99999")
        assert resp.status_code == 404
        data = resp.json()
        assert data["error"] == "Product not found"


class TestGetProductAfterCreate:
    def test_created_product_is_retrievable(self):
        # Create
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Retrievable Product",
            "price": 42.50,
            "stock": 100,
            "description": "Test retrieval"
        })
        assert create_resp.status_code == 201
        created = create_resp.json()
        pid = created["id"]

        # Retrieve
        get_resp = requests.get(f"{BASE_URL}/api/products/{pid}")
        assert get_resp.status_code == 200
        data = get_resp.json()
        assert data["id"] == pid
        assert data["name"] == "Retrievable Product"
        assert float(data["price"]) == 42.50
        assert int(data["stock"]) == 100
