import base64
import json
from urllib.parse import parse_qs

import httpx
import pytest

from app.mercure import MercurePublisher, _jwt


def decode_segment(segment: str) -> dict:
    return json.loads(base64.urlsafe_b64decode(segment + "=" * (-len(segment) % 4)))


def test_jwt_contains_publish_claim_scoped_to_topic():
    token = _jwt("secret", "/devices/abc")
    header, payload, _ = token.split(".")
    assert decode_segment(header)["alg"] == "HS256"
    claims = decode_segment(payload)
    assert claims["mercure"]["publish"] == ["/devices/abc"]
    assert claims["exp"] > 0


def _mock_transport(status: int = 200):
    captured = {}

    def handler(request: httpx.Request) -> httpx.Response:
        captured["request"] = request
        return httpx.Response(status, text="ok")

    return httpx.MockTransport(handler), captured


async def test_publish_telemetry_posts_to_hub_with_bearer_auth():
    transport, captured = _mock_transport()
    publisher = MercurePublisher("http://caddy/.well-known/mercure", "secret")
    publisher._client = httpx.AsyncClient(transport=transport)

    await publisher.publish_telemetry(
        "019fe9fe-c87d-7c6e-a0d9-b7197cd962ba",
        "2026-08-12T04:44:41+00:00",
        [{"field": "temperature", "value": 22.5, "type": "float", "quality": 0}],
    )
    await publisher.aclose()

    request = captured["request"]
    assert request.method == "POST"
    assert str(request.url) == "http://caddy/.well-known/mercure"
    auth = request.headers["Authorization"]
    assert auth.startswith("Bearer ")
    claims = decode_segment(auth.split(".")[1])
    assert claims["mercure"]["publish"] == ["/devices/019fe9fe-c87d-7c6e-a0d9-b7197cd962ba"]
    body = parse_qs(request.content.decode())
    assert body["topic"] == ["/devices/019fe9fe-c87d-7c6e-a0d9-b7197cd962ba"]
    assert json.loads(body["data"][0])["points"][0]["field"] == "temperature"


async def test_publish_failure_is_swallowed():
    transport, _ = _mock_transport(status=500)
    publisher = MercurePublisher("http://caddy/.well-known/mercure", "secret")
    publisher._client = httpx.AsyncClient(transport=transport)

    await publisher.publish_telemetry("d1", "2026-08-12T04:44:41+00:00", [])
    await publisher.aclose()  # must not raise


def test_telemetry_points_grouped_by_device():
    from app.mercure import telemetry_points_by_device

    rows = [
        ("2026-08-12T00:00:00+00:00", "d1", "a", 1.0, "float", 0),
        ("2026-08-12T00:00:00+00:00", "d1", "b", 2.0, "float", 0),
        ("2026-08-12T00:00:00+00:00", "d2", "a", 3.0, "float", 0),
    ]
    assert telemetry_points_by_device(rows) == {
        "d1": [
            {"field": "a", "value": 1.0, "type": "float", "quality": 0},
            {"field": "b", "value": 2.0, "type": "float", "quality": 0},
        ],
        "d2": [{"field": "a", "value": 3.0, "type": "float", "quality": 0}],
    }
