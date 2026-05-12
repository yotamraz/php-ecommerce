"""
Functional tests for the modernized PHP Ecommerce Order API.
Tests all order endpoints and cross-cutting concerns (stock, validation, 409 conflict).
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


def _create_product(name="Order Test Product", price=25.00, stock=100):
    """Helper: create a product for use in order tests."""
    resp = requests.post(f"{BASE_URL}/api/products", json={
        "name": name, "price": price, "stock": stock
    })
    assert resp.status_code == 201, f"Failed to create product: {resp.text}"
    return resp.json()


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
    def test_create_order_success(self):
        product = _create_product("Orderable Widget", 15.00, 50)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": product["id"], "quantity": 2}
            ]
        })
        assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "id" in data
        assert data["status"] == "pending"
        assert float(data["total"]) == 30.00
        assert "items" in data
        assert len(data["items"]) == 1

    def test_create_order_multiple_items(self):
        product1 = _create_product("Multi A", 10.00, 50)
        product2 = _create_product("Multi B", 20.00, 50)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": product1["id"], "quantity": 2},
                {"product_id": product2["id"], "quantity": 1}
            ]
        })
        assert resp.status_code == 201
        data = resp.json()
        assert float(data["total"]) == 40.00  # 2*10 + 1*20
        assert len(data["items"]) == 2

    def test_create_order_decrements_stock(self):
        product = _create_product("Stock Check", 10.00, 20)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": product["id"], "quantity": 5}
            ]
        })
        assert resp.status_code == 201

        # Verify stock was decremented
        get_resp = requests.get(f"{BASE_URL}/api/products/{product['id']}")
        data = get_resp.json()
        assert int(data["stock"]) == 15, f"Expected stock=15, got {data['stock']}"

    def test_create_order_missing_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Order must contain items"

    def test_create_order_empty_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Order must contain items"

    def test_create_order_invalid_quantity(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": 0}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "quantity must be greater than zero" in data["error"]

    def test_create_order_product_not_found(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 99999, "quantity": 1}]
        })
        assert resp.status_code == 404
        data = resp.json()
        assert "not found" in data["error"]

    def test_create_order_insufficient_stock(self):
        product = _create_product("Low Stock", 5.00, 2)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 10}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "Insufficient stock" in data["error"]


class TestGetOrderAfterCreate:
    def test_created_order_is_retrievable(self):
        product = _create_product("Retrievable Order Product", 20.00, 30)

        create_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": product["id"], "quantity": 3}
            ]
        })
        assert create_resp.status_code == 201
        created = create_resp.json()
        oid = created["id"]

        get_resp = requests.get(f"{BASE_URL}/api/orders/{oid}")
        assert get_resp.status_code == 200
        data = get_resp.json()
        assert data["id"] == oid
        assert float(data["total"]) == 60.00
        assert len(data["items"]) == 1
        assert int(data["items"][0]["product_id"]) == product["id"]


class TestDeleteProductReferencedByOrder:
    def test_delete_product_referenced_by_order_returns_409(self):
        product = _create_product("Referenced Product", 10.00, 50)

        # Create an order referencing this product
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert resp.status_code == 201

        # Try to delete — should fail with 409
        del_resp = requests.delete(f"{BASE_URL}/api/products/{product['id']}")
        assert del_resp.status_code == 409, f"Expected 409, got {del_resp.status_code}: {del_resp.text}"
        data = del_resp.json()
        assert "referenced by orders" in data["error"]
