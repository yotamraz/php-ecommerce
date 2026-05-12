"""
Functional tests for the modernized PHP Ecommerce Order API.
Tests all order endpoints against a running instance.
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


def _create_product(name="Order Test Product", price=29.99, stock=100):
    """Helper to create a product for order tests."""
    resp = requests.post(f"{BASE_URL}/api/products", json={
        "name": name,
        "price": price,
        "stock": stock,
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


class TestCreateOrderValidation:
    def test_missing_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items array is required"

    def test_empty_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items array is required"

    def test_non_array_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": "not-an-array"})
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "items array is required"

    def test_item_missing_product_id_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"quantity": 2}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Each item needs product_id and quantity"

    def test_item_missing_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Each item needs product_id and quantity"

    def test_zero_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": 0}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Quantity must be at least 1"

    def test_negative_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": -1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert data["error"] == "Quantity must be at least 1"

    def test_nonexistent_product_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 99999, "quantity": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "not found" in data["error"]

    def test_insufficient_stock_returns_400(self):
        product = _create_product("Low Stock Item", 10.00, 2)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 100}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "Insufficient stock" in data["error"]


class TestCreateOrderSuccess:
    def test_create_order_returns_201(self):
        product = _create_product("Order Success Product", 25.00, 50)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 2}]
        })
        assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "id" in data
        assert data["status"] == "pending"
        assert float(data["total"]) == 50.00
        assert "items" in data
        assert len(data["items"]) == 1

    def test_create_order_with_multiple_items(self):
        product1 = _create_product("Multi A", 10.00, 50)
        product2 = _create_product("Multi B", 20.00, 50)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": product1["id"], "quantity": 3},
                {"product_id": product2["id"], "quantity": 2},
            ]
        })
        assert resp.status_code == 201
        data = resp.json()
        # Total = 10.00 * 3 + 20.00 * 2 = 70.00
        assert float(data["total"]) == 70.00
        assert len(data["items"]) == 2

    def test_create_order_decrements_stock(self):
        product = _create_product("Stock Test", 15.00, 20)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 5}]
        })
        assert resp.status_code == 201

        # Check stock decreased
        get_resp = requests.get(f"{BASE_URL}/api/products/{product['id']}")
        assert get_resp.status_code == 200
        updated = get_resp.json()
        assert int(updated["stock"]) == 15

    def test_order_items_have_product_name(self):
        product = _create_product("Named Product", 30.00, 10)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert resp.status_code == 201
        data = resp.json()
        assert data["items"][0]["product_name"] == "Named Product"


class TestGetOrderAfterCreate:
    def test_created_order_is_retrievable(self):
        product = _create_product("Retrievable Order Product", 42.50, 100)

        # Create order
        create_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 2}]
        })
        assert create_resp.status_code == 201
        created = create_resp.json()
        order_id = created["id"]

        # Retrieve it
        get_resp = requests.get(f"{BASE_URL}/api/orders/{order_id}")
        assert get_resp.status_code == 200
        data = get_resp.json()
        assert data["id"] == order_id
        assert float(data["total"]) == 85.00
        assert len(data["items"]) == 1
        assert int(data["items"][0]["product_id"]) == product["id"]
        assert data["items"][0]["product_name"] == "Retrievable Order Product"


class TestDeleteProductWithOrders:
    def test_cannot_delete_product_with_orders(self):
        """Products referenced by orders cannot be deleted (409 Conflict)."""
        product = _create_product("Protected Product", 20.00, 50)

        # Create an order referencing this product
        order_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert order_resp.status_code == 201

        # Try to delete the product — should fail with 409
        delete_resp = requests.delete(f"{BASE_URL}/api/products/{product['id']}")
        assert delete_resp.status_code == 409
        data = delete_resp.json()
        assert "Cannot delete product" in data["error"]
