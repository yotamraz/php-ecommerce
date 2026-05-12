"""
Functional tests for the modernized PHP Ecommerce Order API.
Tests all order endpoints against a running instance.
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


def create_product(name="Order Test Product", price=25.00, stock=100):
    """Helper: create a product and return its data."""
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
        product = create_product("Create Order Product", 29.99, 50)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": product["id"], "quantity": 2}
            ]
        })
        assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "id" in data
        assert data["status"] == "pending"
        assert abs(float(data["total"]) - 59.98) < 0.01
        assert "items" in data
        assert len(data["items"]) == 1
        assert int(data["items"][0]["product_id"]) == product["id"]
        assert int(data["items"][0]["quantity"]) == 2

    def test_create_order_decreases_stock(self):
        product = create_product("Stock Decrease Product", 10.00, 20)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 5}]
        })
        assert resp.status_code == 201

        # Verify stock decreased
        get_resp = requests.get(f"{BASE_URL}/api/products/{product['id']}")
        assert get_resp.status_code == 200
        assert int(get_resp.json()["stock"]) == 15

    def test_create_order_multiple_items(self):
        p1 = create_product("Multi A", 10.00, 50)
        p2 = create_product("Multi B", 20.00, 50)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": p1["id"], "quantity": 3},
                {"product_id": p2["id"], "quantity": 2},
            ]
        })
        assert resp.status_code == 201
        data = resp.json()
        # 3 * 10.00 + 2 * 20.00 = 70.00
        assert abs(float(data["total"]) - 70.00) < 0.01
        assert len(data["items"]) == 2

    def test_create_order_missing_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items are required"

    def test_create_order_empty_items(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items must be a non-empty array"

    def test_create_order_missing_product_id(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"quantity": 2}]
        })
        assert resp.status_code == 400
        assert "product_id is required" in resp.json()["error"]

    def test_create_order_missing_quantity(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1}]
        })
        assert resp.status_code == 400
        assert "quantity is required" in resp.json()["error"]

    def test_create_order_zero_quantity(self):
        product = create_product("Zero Qty", 10.00, 50)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 0}]
        })
        assert resp.status_code == 400
        assert "quantity must be greater than zero" in resp.json()["error"]

    def test_create_order_negative_quantity(self):
        product = create_product("Neg Qty", 10.00, 50)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": -3}]
        })
        assert resp.status_code == 400
        assert "quantity must be greater than zero" in resp.json()["error"]

    def test_create_order_product_not_found(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 99999, "quantity": 1}]
        })
        assert resp.status_code == 400
        assert "not found" in resp.json()["error"]

    def test_create_order_insufficient_stock(self):
        product = create_product("Low Stock", 10.00, 2)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 10}]
        })
        assert resp.status_code == 409
        assert "Insufficient stock" in resp.json()["error"]


class TestGetOrderAfterCreate:
    def test_created_order_is_retrievable(self):
        product = create_product("Retrievable Order Product", 15.00, 30)

        create_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 2}]
        })
        assert create_resp.status_code == 201
        created = create_resp.json()
        order_id = created["id"]

        get_resp = requests.get(f"{BASE_URL}/api/orders/{order_id}")
        assert get_resp.status_code == 200
        data = get_resp.json()
        assert data["id"] == order_id
        assert data["status"] == "pending"
        assert abs(float(data["total"]) - 30.00) < 0.01
        assert "items" in data
        assert len(data["items"]) == 1


class TestDeleteProductWithOrders:
    def test_delete_product_with_orders_returns_409(self):
        product = create_product("Undeletable Product", 10.00, 50)

        # Create an order referencing this product
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert resp.status_code == 201

        # Try to delete — should fail with 409
        del_resp = requests.delete(f"{BASE_URL}/api/products/{product['id']}")
        assert del_resp.status_code == 409
        assert "referenced by orders" in del_resp.json()["error"]
