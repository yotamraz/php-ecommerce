"""
Functional self-verification tests for the PHP Ecommerce API (Milestone 2).
Tests all endpoints including order management, input validation, and queue integration.
"""
import requests
import pytest

BASE_URL = "http://localhost:9090"


# --- Health endpoint ---

class TestHealth:
    def test_health_returns_200(self):
        resp = requests.get(f"{BASE_URL}/health")
        assert resp.status_code == 200
        data = resp.json()
        assert "status" in data or "mysql" in data or isinstance(data, dict)

    def test_health_has_json_content_type(self):
        resp = requests.get(f"{BASE_URL}/health")
        assert "application/json" in resp.headers.get("Content-Type", "")


# --- Product CRUD ---

class TestProducts:
    def test_list_products_returns_200(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        assert resp.status_code == 200
        data = resp.json()
        assert isinstance(data, list)

    def test_list_products_has_seeded_data(self):
        resp = requests.get(f"{BASE_URL}/api/products")
        assert resp.status_code == 200
        data = resp.json()
        assert len(data) >= 1  # At least the seeded products

    def test_get_product_not_found(self):
        resp = requests.get(f"{BASE_URL}/api/products/99999")
        assert resp.status_code == 404
        data = resp.json()
        assert "error" in data

    def test_create_product_success(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Test Widget",
            "price": 25.99,
            "stock": 10,
            "description": "A test product",
        })
        assert resp.status_code == 201
        data = resp.json()
        assert data["name"] == "Test Widget"
        assert float(data["price"]) == 25.99
        assert int(data["stock"]) == 10
        assert "id" in data

    def test_create_product_missing_name_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "price": 10.00,
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Validation failed"
        assert "details" in data

    def test_create_product_missing_price_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "No Price",
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Validation failed"

    def test_create_product_empty_body_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Validation failed"

    def test_create_product_negative_price_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Negative Price",
            "price": -5,
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Validation failed"

    def test_create_product_zero_price_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Zero Price",
            "price": 0,
        })
        assert resp.status_code == 400

    def test_create_product_negative_stock_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Bad Stock",
            "price": 10,
            "stock": -1,
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Validation failed"

    def test_create_product_empty_name_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "",
            "price": 10,
        })
        assert resp.status_code == 400

    def test_get_product_after_create(self):
        # Create
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Fetchable Widget",
            "price": 15.00,
            "stock": 5,
        })
        assert create_resp.status_code == 201
        created = create_resp.json()

        # Get
        resp = requests.get(f"{BASE_URL}/api/products/{created['id']}")
        assert resp.status_code == 200
        data = resp.json()
        assert data["id"] == created["id"]
        assert data["name"] == "Fetchable Widget"

    def test_update_product_success(self):
        # Create
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Updatable Widget",
            "price": 10.00,
            "stock": 5,
        })
        created = create_resp.json()

        # Update
        resp = requests.put(f"{BASE_URL}/api/products/{created['id']}", json={
            "name": "Updated Widget",
            "price": 20.00,
        })
        assert resp.status_code == 200
        data = resp.json()
        assert data["name"] == "Updated Widget"
        assert float(data["price"]) == 20.00

    def test_update_product_not_found(self):
        resp = requests.put(f"{BASE_URL}/api/products/99999", json={
            "name": "Ghost",
        })
        assert resp.status_code == 404

    def test_update_product_no_fields(self):
        # Create first
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Empty Update",
            "price": 10.00,
        })
        created = create_resp.json()

        resp = requests.put(f"{BASE_URL}/api/products/{created['id']}", json={})
        assert resp.status_code == 400

    def test_delete_product_success(self):
        # Create
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Deletable Widget",
            "price": 5.00,
        })
        created = create_resp.json()

        # Delete
        resp = requests.delete(f"{BASE_URL}/api/products/{created['id']}")
        assert resp.status_code == 204

        # Verify gone
        resp = requests.get(f"{BASE_URL}/api/products/{created['id']}")
        assert resp.status_code == 404

    def test_delete_product_not_found(self):
        resp = requests.delete(f"{BASE_URL}/api/products/99999")
        assert resp.status_code == 404


# --- Order Management ---

class TestOrders:
    def test_list_orders_returns_200(self):
        resp = requests.get(f"{BASE_URL}/api/orders")
        assert resp.status_code == 200
        data = resp.json()
        assert isinstance(data, list)

    def test_create_order_success(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": 1, "quantity": 1},
            ],
        })
        assert resp.status_code == 201
        data = resp.json()
        assert "id" in data
        assert "total" in data
        assert data["status"] == "pending"
        assert "items" in data
        assert len(data["items"]) >= 1

    def test_create_order_multiple_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": 1, "quantity": 1},
                {"product_id": 2, "quantity": 2},
            ],
        })
        assert resp.status_code == 201
        data = resp.json()
        assert len(data["items"]) == 2
        assert float(data["total"]) > 0

    def test_get_order_after_create(self):
        # Create
        create_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": 1}],
        })
        assert create_resp.status_code == 201
        created = create_resp.json()

        # Get
        resp = requests.get(f"{BASE_URL}/api/orders/{created['id']}")
        assert resp.status_code == 200
        data = resp.json()
        assert data["id"] == created["id"]
        assert "items" in data

    def test_get_order_not_found(self):
        resp = requests.get(f"{BASE_URL}/api/orders/99999")
        assert resp.status_code == 404
        data = resp.json()
        assert "not found" in data["error"].lower()

    # --- Validation tests ---

    def test_create_order_missing_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400
        data = resp.json()
        # NestedValidationException -> "Validation failed"
        assert data["error"] == "Validation failed"
        assert "details" in data

    def test_create_order_empty_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400

    def test_create_order_items_not_array_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": "invalid"})
        assert resp.status_code == 400

    def test_create_order_missing_product_id_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"quantity": 1}],
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "items[0]" in data["error"]

    def test_create_order_missing_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1}],
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "items[0]" in data["error"]

    def test_create_order_zero_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": 0}],
        })
        assert resp.status_code == 400

    def test_create_order_negative_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": -3}],
        })
        assert resp.status_code == 400

    def test_create_order_nonexistent_product_returns_404(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 99999, "quantity": 1}],
        })
        assert resp.status_code == 404
        data = resp.json()
        assert "not found" in data["error"].lower()

    # --- Stock management ---

    def test_order_decrements_stock(self):
        # Create a product with known stock
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Stock Test Widget",
            "price": 10.00,
            "stock": 5,
        })
        assert create_resp.status_code == 201
        product = create_resp.json()
        pid = product["id"]

        # Order 2 of it
        order_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": pid, "quantity": 2}],
        })
        assert order_resp.status_code == 201

        # Check stock decreased
        get_resp = requests.get(f"{BASE_URL}/api/products/{pid}")
        assert get_resp.status_code == 200
        updated = get_resp.json()
        assert int(updated["stock"]) == 3

    def test_order_total_calculation(self):
        # Create products with known prices
        p1 = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Calc Test A",
            "price": 10.00,
            "stock": 100,
        }).json()
        p2 = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Calc Test B",
            "price": 25.50,
            "stock": 100,
        }).json()

        # Order: 2 * 10.00 + 3 * 25.50 = 20.00 + 76.50 = 96.50
        order_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": p1["id"], "quantity": 2},
                {"product_id": p2["id"], "quantity": 3},
            ],
        })
        assert order_resp.status_code == 201
        order = order_resp.json()
        assert float(order["total"]) == pytest.approx(96.50, abs=0.01)


# --- 404 for unknown routes ---

class TestMisc:
    def test_unknown_route_returns_404(self):
        resp = requests.get(f"{BASE_URL}/api/nonexistent")
        assert resp.status_code == 404
