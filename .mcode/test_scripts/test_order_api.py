"""
Functional tests for the modernized PHP Ecommerce Order API.
Tests all order endpoints against a running instance.
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


class TestListOrders:
    def test_list_orders_returns_200(self):
        resp = requests.get(f"{BASE_URL}/api/orders")
        assert resp.status_code == 200

    def test_list_orders_returns_array(self):
        resp = requests.get(f"{BASE_URL}/api/orders")
        data = resp.json()
        assert isinstance(data, list), f"Expected list, got {type(data)}"

    def test_list_orders_json_content_type(self):
        resp = requests.get(f"{BASE_URL}/api/orders")
        assert "application/json" in resp.headers.get("Content-Type", "")


class TestGetOrder:
    def test_get_nonexistent_order_returns_404(self):
        resp = requests.get(f"{BASE_URL}/api/orders/99999")
        assert resp.status_code == 404
        data = resp.json()
        assert data["error"] == "Order not found"


class TestCreateOrder:
    @staticmethod
    def _create_product(name="Order Test Product", price=10.00, stock=100):
        resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": name, "price": price, "stock": stock
        })
        assert resp.status_code == 201, f"Product setup failed: {resp.text}"
        return resp.json()

    def test_create_order_success(self):
        product = self._create_product("Order Widget", 25.00, 50)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 2}]
        })
        assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "id" in data
        assert float(data["total"]) == 50.00
        assert "items" in data
        assert len(data["items"]) == 1
        assert int(data["items"][0]["product_id"]) == product["id"]
        assert int(data["items"][0]["quantity"]) == 2

    def test_create_order_decrements_stock(self):
        product = self._create_product("Stock Decrement", 5.00, 20)
        requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 3}]
        })
        # Verify stock was decremented
        get_resp = requests.get(f"{BASE_URL}/api/products/{product['id']}")
        assert get_resp.status_code == 200
        updated = get_resp.json()
        assert int(updated["stock"]) == 17

    def test_create_order_multiple_items(self):
        p1 = self._create_product("Multi A", 10.00, 50)
        p2 = self._create_product("Multi B", 20.00, 30)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": p1["id"], "quantity": 1},
                {"product_id": p2["id"], "quantity": 2},
            ]
        })
        assert resp.status_code == 201
        data = resp.json()
        assert float(data["total"]) == 50.00  # 10 + 2*20
        assert len(data["items"]) == 2

    def test_create_order_missing_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items array is required"

    def test_create_order_empty_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items array is required"

    def test_create_order_item_missing_product_id(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"quantity": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Each item needs product_id and quantity"

    def test_create_order_item_missing_quantity(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Each item needs product_id and quantity"

    def test_create_order_zero_quantity(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": 0}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Quantity must be at least 1"

    def test_create_order_negative_quantity(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": -2}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Quantity must be at least 1"

    def test_create_order_product_not_found(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 99999, "quantity": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "Product 99999 not found" in data["error"]

    def test_create_order_insufficient_stock(self):
        product = self._create_product("Low Stock", 10.00, 2)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 5}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "Insufficient stock" in data["error"]


class TestGetOrderAfterCreate:
    def test_created_order_is_retrievable(self):
        # Create a product
        create_product_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Retrievable Order Product",
            "price": 15.00,
            "stock": 50,
        })
        assert create_product_resp.status_code == 201
        product = create_product_resp.json()

        # Create an order
        create_order_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 3}]
        })
        assert create_order_resp.status_code == 201
        created = create_order_resp.json()
        order_id = created["id"]

        # Retrieve the order
        get_resp = requests.get(f"{BASE_URL}/api/orders/{order_id}")
        assert get_resp.status_code == 200
        data = get_resp.json()
        assert int(data["id"]) == order_id
        assert float(data["total"]) == 45.00  # 15 * 3
        assert "items" in data
        assert len(data["items"]) == 1
        assert data["items"][0]["product_name"] == "Retrievable Order Product"

    def test_order_appears_in_list(self):
        # Create a product and order
        create_product_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "List Check Product",
            "price": 5.00,
            "stock": 10,
        })
        assert create_product_resp.status_code == 201
        product = create_product_resp.json()

        create_order_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert create_order_resp.status_code == 201
        created = create_order_resp.json()

        # Verify it appears in the list
        list_resp = requests.get(f"{BASE_URL}/api/orders")
        assert list_resp.status_code == 200
        orders = list_resp.json()
        order_ids = [int(o["id"]) for o in orders]
        assert created["id"] in order_ids


class TestDeleteProductWithOrders:
    def test_cannot_delete_product_with_orders(self):
        """Products referenced by orders cannot be deleted (409 Conflict)."""
        # Create a product
        create_resp = requests.post(f"{BASE_URL}/api/products", json={
            "name": "Undeletable Product",
            "price": 10.00,
            "stock": 50,
        })
        assert create_resp.status_code == 201
        product = create_resp.json()

        # Create an order referencing this product
        order_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert order_resp.status_code == 201

        # Try to delete the product - should fail with 409
        del_resp = requests.delete(f"{BASE_URL}/api/products/{product['id']}")
        assert del_resp.status_code == 409
        data = del_resp.json()
        assert "Cannot delete product" in data["error"]
