"""Debug test to understand body parsing issue."""
import requests
import json

BASE_URL = "http://localhost:8081"

def test_post_with_content_type():
    """Test POST with explicit Content-Type header."""
    payload = {"name": "Debug Product", "price": 19.99, "stock": 5}
    headers = {"Content-Type": "application/json"}
    resp = requests.post(f"{BASE_URL}/api/products", data=json.dumps(payload), headers=headers)
    print(f"Status: {resp.status_code}")
    print(f"Body: {resp.text}")
    print(f"Request headers sent: {resp.request.headers}")
    assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"

def test_post_with_json_kwarg():
    """Test POST using requests json= kwarg."""
    payload = {"name": "Debug Product 2", "price": 29.99}
    resp = requests.post(f"{BASE_URL}/api/products", json=payload)
    print(f"Status: {resp.status_code}")
    print(f"Body: {resp.text}")
    print(f"Request headers sent: {resp.request.headers}")
    assert resp.status_code == 201, f"Expected 201, got {resp.status_code}: {resp.text}"
