"""Mock HTTP device driver.

Posts JSON bodies to the platform's HTTP ingest endpoint
(`POST /ingest/http/{device_id}`) authenticated with the device API key — the
same flow an HTTP/REST push device uses. Each accepted body is a flat JSON
object whose numeric fields become telemetry points.
"""

import requests


def push_samples(
    *,
    base_url: str,
    device_id: str,
    api_key: str,
    samples: list[dict],
) -> None:
    """Push each sample and assert the ingest endpoint accepts it (HTTP 202)."""
    url = f"{base_url.rstrip('/')}/ingest/http/{device_id}"
    for payload in samples:
        response = requests.post(
            url, json=payload, headers={"X-API-Key": api_key}, timeout=20
        )
        response.raise_for_status()
        body = response.json()
        if not body.get("accepted"):
            raise AssertionError(f"HTTP ingest rejected payload: {body}")
