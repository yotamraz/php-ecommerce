"""
Functional tests for the PHP ecommerce API - Milestone 2 (Order Management).
Tests all order endpoints plus product endpoints to verify nothing is broken.
"""
import requests
import pytest

BASE_URL = "http://localhost:8082"


# --- Health endpoint ---

def test_health_endpoint():
    """Health endpoint should return 200 with status field."""
    resp = requests.get(f"{BASE_URL}/health")
    assert resp.status_code == 200
    data = resp.json()
    assert "status" in data


# --- Product endpoints (regression) ---

def test_list_products():
    """GET /api/products should return a list."""
    resp = requests.get(f"{BASE_URL}/api/products")
    assert resp.status_code == 200
    data = resp.json()
    assert isinstance(data, list)
    assert len(data) > 0


def test_get_product():
    """GET /api/products/1 should return a product."""
    resp = requests.get(f"{BASE_URL}/api/products/1")
    assert resp.status_code == 200
    data = resp.json()
    assert data["id"] == 1
    assert "name" in data
    assert "price" in data


def test_get_product_not_found():
    """GET /api/products/99999 should return 404."""
    resp = requests.get(f"{BASE_URL}/api/products/99999")
    assert resp.status_code == 404


def test_create_product():
    """POST /api/products should create a product."""
    resp = requests.post(f"{BASE_URL}/api/products", json={
        "name": "Test Product M2",
        "price": 15.99,
        "stock": 100,
    })
    assert resp.status_code == 201
    data = resp.json()
    assert data["name"] == "Test Product M2"
    assert float(data["price"]) == 15.99


def test_create_product_validation_missing_fields():
    """POST /api/products with missing fields should return 400."""
    resp = requests.post(f"{BASE_URL}/api/products", json={})
    assert resp.status_code == 400


def test_create_product_validation_negative_price():
    """POST /api/products with negative price should return 400."""
    resp = requests.post(f"{BASE_URL}/api/products", json={
        "name": "Bad Product",
        "price": -10,
    })
    assert resp.status_code == 400


# --- Order endpoints ---

def test_list_orders():
    """GET /api/orders should return a list (possibly empty)."""
    resp = requests.get(f"{BASE_URL}/api/orders")
    assert resp.status_code == 200
    data = resp.json()
    assert isinstance(data, list)


def test_create_order_missing_items():
    """POST /api/orders without items should return 400."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={})
    assert resp.status_code == 400
    data = resp.json()
    assert "error" in data
    assert "items" in data["error"].lower()


def test_create_order_empty_items():
    """POST /api/orders with empty items array should return 400."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={"items": []})
    assert resp.status_code == 400
    data = resp.json()
    assert "error" in data


def test_create_order_missing_product_id():
    """POST /api/orders item without product_id should return 400."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"quantity": 1}]
    })
    assert resp.status_code == 400
    data = resp.json()
    assert "product_id" in data["error"]


def test_create_order_missing_quantity():
    """POST /api/orders item without quantity should return 400."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 1}]
    })
    assert resp.status_code == 400
    data = resp.json()
    assert "quantity" in data["error"]


def test_create_order_zero_quantity():
    """POST /api/orders with zero quantity should return 400."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 1, "quantity": 0}]
    })
    assert resp.status_code == 400


def test_create_order_negative_quantity():
    """POST /api/orders with negative quantity should return 400."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 1, "quantity": -5}]
    })
    assert resp.status_code == 400


def test_create_order_nonexistent_product():
    """POST /api/orders with non-existent product should return 404."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 99999, "quantity": 1}]
    })
    assert resp.status_code == 404
    data = resp.json()
    assert "not found" in data["error"].lower()


def test_create_order_success_single_item():
    """POST /api/orders with valid single item should return 201."""
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 1, "quantity": 1}]
    })
    assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
    data = resp.json()
    assert "id" in data
    assert data["status"] == "pending"
    assert float(data["total"]) > 0
    assert "items" in data
    assert len(data["items"]) == 1


def test_create_order_success_multiple_items():
    """POST /api/orders with multiple items should return 201 with correct total."""
    # First get product prices
    p1 = requests.get(f"{BASE_URL}/api/products/1").json()
    p2 = requests.get(f"{BASE_URL}/api/products/2").json()

    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [
            {"product_id": 1, "quantity": 2},
            {"product_id": 2, "quantity": 1},
        ]
    })
    assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
    data = resp.json()
    assert len(data["items"]) == 2
    expected_total = float(p1["price"]) * 2 + float(p2["price"]) * 1
    assert abs(float(data["total"]) - expected_total) < 0.01, \
        f"Expected total {expected_total}, got {data['total']}"


def test_get_order_not_found():
    """GET /api/orders/99999 should return 404."""
    resp = requests.get(f"{BASE_URL}/api/orders/99999")
    assert resp.status_code == 404
    data = resp.json()
    assert "error" in data


def test_get_order_success():
    """GET /api/orders/{id} should return the order with items."""
    # Create an order first
    create_resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 1, "quantity": 1}]
    })
    assert create_resp.status_code == 201
    order_id = create_resp.json()["id"]

    # Retrieve it
    resp = requests.get(f"{BASE_URL}/api/orders/{order_id}")
    assert resp.status_code == 200
    data = resp.json()
    assert data["id"] == order_id
    assert "items" in data
    assert len(data["items"]) >= 1
    assert data["status"] == "pending"


def test_create_order_decrements_stock():
    """Creating an order should decrease the product stock."""
    # Create a product with known stock
    create_p = requests.post(f"{BASE_URL}/api/products", json={
        "name": "Stock Test Product",
        "price": 5.00,
        "stock": 10,
    })
    assert create_p.status_code == 201
    product_id = create_p.json()["id"]
    initial_stock = int(create_p.json()["stock"])

    # Create an order for 3 units
    order_resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": product_id, "quantity": 3}]
    })
    assert order_resp.status_code == 201

    # Check stock decreased
    product_resp = requests.get(f"{BASE_URL}/api/products/{product_id}")
    assert product_resp.status_code == 200
    new_stock = int(product_resp.json()["stock"])
    assert new_stock == initial_stock - 3, \
        f"Expected stock {initial_stock - 3}, got {new_stock}"


def test_create_order_insufficient_stock():
    """Creating an order for more than available stock should return 409."""
    # Create a product with low stock
    create_p = requests.post(f"{BASE_URL}/api/products", json={
        "name": "Low Stock Product",
        "price": 5.00,
        "stock": 2,
    })
    assert create_p.status_code == 201
    product_id = create_p.json()["id"]

    # Try to order more than stock
    resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": product_id, "quantity": 10}]
    })
    assert resp.status_code == 409
    data = resp.json()
    assert "insufficient stock" in data["error"].lower() or "stock" in data["error"].lower()


def test_orders_appear_in_list():
    """After creating an order, it should appear in the orders list."""
    # Create an order
    create_resp = requests.post(f"{BASE_URL}/api/orders", json={
        "items": [{"product_id": 1, "quantity": 1}]
    })
    assert create_resp.status_code == 201
    order_id = create_resp.json()["id"]

    # Check it's in the list
    list_resp = requests.get(f"{BASE_URL}/api/orders")
    assert list_resp.status_code == 200
    orders = list_resp.json()
    order_ids = [o["id"] for o in orders]
    assert order_id in order_ids
