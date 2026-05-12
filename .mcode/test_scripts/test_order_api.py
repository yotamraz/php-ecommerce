"""
Functional tests for the modernized PHP Ecommerce Order API.
Tests all order endpoints, validation, transactional stock management,
and proper error handling against a running instance.
"""
import requests
import pytest

BASE_URL = "http://localhost:8081"


def _create_product(name="Order Test Product", price=29.99, stock=100):
    """Helper to create a product for order testing."""
    resp = requests.post(f"{BASE_URL}/api/products", json={
        "name": name,
        "price": price,
        "stock": stock,
        "description": "Created for order tests"
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
        assert "error" in data
        assert "not found" in data["error"].lower()


class TestCreateOrderValidation:
    def test_create_order_without_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={})
        assert resp.status_code == 400
        data = resp.json()
        assert "error" in data
        assert "items" in data["error"].lower()

    def test_create_order_empty_items_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
        assert resp.status_code == 400
        data = resp.json()
        assert "error" in data

    def test_create_order_missing_product_id_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"quantity": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "error" in data
        assert "product_id" in data["error"].lower()

    def test_create_order_missing_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1}]
        })
        assert resp.status_code == 400
        data = resp.json()
        assert "error" in data
        assert "quantity" in data["error"].lower()

    def test_create_order_zero_quantity_returns_400(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 1, "quantity": 0}]
        })
        assert resp.status_code == 400

    def test_create_order_nonexistent_product_returns_error(self):
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": 99999, "quantity": 1}]
        })
        # Product not found should return 404
        assert resp.status_code in [404, 400], f"Expected 404 or 400, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "error" in data


class TestCreateOrderSuccess:
    def test_create_order_returns_201(self):
        product = _create_product(name="201 Test", price=15.50, stock=100)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 2}]
        })
        assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "id" in data
        assert "total" in data
        assert "items" in data
        assert "status" in data
        assert data["status"] == "pending"

    def test_create_order_calculates_correct_total(self):
        product = _create_product(name="Total Calc", price=25.00, stock=100)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 4}]
        })
        assert resp.status_code == 201
        data = resp.json()
        assert float(data["total"]) == 100.00, f"Expected 100.00, got {data['total']}"

    def test_create_order_decrements_stock(self):
        product = _create_product(name="Stock Decrement", price=10.00, stock=50)
        pid = product["id"]

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": pid, "quantity": 3}]
        })
        assert resp.status_code == 201

        # Verify stock was decremented
        get_resp = requests.get(f"{BASE_URL}/api/products/{pid}")
        updated = get_resp.json()
        assert int(updated["stock"]) == 47, f"Expected stock 47, got {updated['stock']}"

    def test_create_order_with_multiple_items(self):
        p1 = _create_product(name="Multi Item A", price=10.00, stock=100)
        p2 = _create_product(name="Multi Item B", price=20.00, stock=100)

        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [
                {"product_id": p1["id"], "quantity": 2},
                {"product_id": p2["id"], "quantity": 3},
            ]
        })
        assert resp.status_code == 201
        data = resp.json()
        # Total = (10*2) + (20*3) = 20 + 60 = 80
        assert float(data["total"]) == 80.00, f"Expected 80.00, got {data['total']}"
        assert len(data["items"]) == 2

    def test_create_order_insufficient_stock_returns_409(self):
        product = _create_product(name="Low Stock", price=5.00, stock=2)
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 10}]
        })
        assert resp.status_code == 409, f"Expected 409, got {resp.status_code}: {resp.text}"
        data = resp.json()
        assert "error" in data
        assert "stock" in data["error"].lower()


class TestGetCreatedOrder:
    def test_get_order_returns_correct_data(self):
        product = _create_product(name="Get Order Test", price=19.99, stock=20)
        pid = product["id"]

        # Create order
        create_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": pid, "quantity": 1}]
        })
        assert create_resp.status_code == 201
        order_id = create_resp.json()["id"]

        # Retrieve order
        get_resp = requests.get(f"{BASE_URL}/api/orders/{order_id}")
        assert get_resp.status_code == 200
        data = get_resp.json()
        assert data["id"] == order_id
        assert data["status"] == "pending"
        assert "items" in data
        assert len(data["items"]) >= 1
        assert float(data["total"]) == 19.99

    def test_created_order_appears_in_list(self):
        product = _create_product(name="List Order Test", price=9.99, stock=50)

        create_resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": product["id"], "quantity": 1}]
        })
        assert create_resp.status_code == 201
        order_id = create_resp.json()["id"]

        # List all orders
        list_resp = requests.get(f"{BASE_URL}/api/orders")
        assert list_resp.status_code == 200
        orders = list_resp.json()
        order_ids = [o["id"] for o in orders]
        assert order_id in order_ids, f"Order {order_id} not found in list: {order_ids}"


class TestDeleteProductWithOrders:
    def test_delete_product_referenced_by_order_returns_409(self):
        product = _create_product(name="Delete Blocked", price=15.00, stock=50)
        pid = product["id"]

        # Create order referencing this product
        resp = requests.post(f"{BASE_URL}/api/orders", json={
            "items": [{"product_id": pid, "quantity": 1}]
        })
        assert resp.status_code == 201

        # Try to delete the product — should fail with 409
        del_resp = requests.delete(f"{BASE_URL}/api/products/{pid}")
        assert del_resp.status_code == 409, f"Expected 409, got {del_resp.status_code}: {del_resp.text}"
        data = del_resp.json()
        assert "error" in data
        assert "order" in data["error"].lower()
