"""Shared helpers for the E2E suite: HTTP API client, Mercure SSE subscriber
and polling/timeout utilities.

The API client talks to the Caddy edge proxy (`E2E_BASE_URL`), so it exercises
the same routing the dashboard uses (`/auth/*` -> auth, `/api/v1/*` ->
device-mgmt, `/.well-known/mercure` -> the Mercure hub). The SSE subscriber
represents a dashboard client: it mints a subscriber JWT via
`/auth/mercure-token` and consumes the same `/devices/{id}` topic stream.
"""

import json
import sys
import threading
import time

import requests


class Api:
    """Minimal JSON client for the Caddy-routed auth + device-mgmt APIs."""

    def __init__(self, base_url: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.token: str | None = None

    def _headers(self) -> dict[str, str]:
        headers = {"Accept": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        return headers

    def login(self, email: str, password: str) -> dict:
        response = requests.post(
            f"{self.base_url}/auth/login",
            json={"email": email, "password": password},
            headers=self._headers(),
            timeout=20,
        )
        response.raise_for_status()
        self.token = response.json()["access_token"]
        return response.json()

    def get(self, path: str) -> dict:
        response = requests.get(
            f"{self.base_url}{path}", headers=self._headers(), timeout=20
        )
        response.raise_for_status()
        return response.json()

    def post(self, path: str, body: dict) -> dict:
        response = requests.post(
            f"{self.base_url}{path}", json=body, headers=self._headers(), timeout=20
        )
        response.raise_for_status()
        return response.json()

    def put(self, path: str, body: dict) -> dict:
        response = requests.put(
            f"{self.base_url}{path}", json=body, headers=self._headers(), timeout=20
        )
        response.raise_for_status()
        return response.json()


class SseStream:
    """Background Mercure SSE subscriber accumulating parsed telemetry events.

    Events are appended to `events` (each a dict with `device_id`, `time` and
    `points`); a `data:` payload may span multiple lines, which are joined. Hub
    heartbeat comments (`: ping`) and other SSE metadata are ignored. The thread
    is a daemon, so it never blocks suite exit.
    """

    def __init__(self, url: str, headers: dict[str, str]) -> None:
        self._url = url
        self._headers = headers
        self.events: list[dict] = []
        self._stop = threading.Event()
        self._thread = threading.Thread(target=self._run, name="mercure-sse", daemon=True)

    def start(self) -> None:
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()

    def _run(self) -> None:
        try:
            with requests.get(
                self._url, stream=True, headers=self._headers, timeout=(10, 120)
            ) as response:
                response.raise_for_status()
                data_lines: list[str] = []
                for line in response.iter_lines(decode_unicode=True):
                    if self._stop.is_set():
                        return
                    if line is None or line.startswith(":"):
                        continue
                    if line == "":
                        self._dispatch("\n".join(data_lines))
                        data_lines = []
                        continue
                    if line.startswith("data:"):
                        data_lines.append(line[5:].strip())
        except Exception as exc:  # noqa: BLE001 - suite fails on wait timeouts
            print(f"[sse] stream error: {exc}", file=sys.stderr)

    def _dispatch(self, data: str) -> None:
        try:
            self.events.append(json.loads(data))
        except json.JSONDecodeError:
            print(f"[sse] ignoring non-JSON event: {data!r}", file=sys.stderr)


def wait_for_event(
    stream: SseStream, device_id: str, field: str, timeout: float
) -> dict | None:
    """Block until an SSE telemetry event for `device_id` contains `field`."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        for event in stream.events:
            if event.get("device_id") == device_id and any(
                point.get("field") == field for point in event.get("points", [])
            ):
                return event
        time.sleep(0.5)
    return None


def wait_for_field(api: Api, device_id: str, field: str, timeout: float) -> dict | None:
    """Block until `GET /devices/{id}/last` exposes `field`, then return it."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            last = api.get(f"/api/v1/devices/{device_id}/last").get("last", {})
        except Exception:  # noqa: BLE001 - device-mgmt may still be warming up
            last = {}
        if field in last:
            return last[field]
        time.sleep(1)
    return None


def has_series_point(api: Api, device_id: str, field: str) -> bool:
    """True when the bucketed series endpoint returns at least one point.

    The `from` window uses the `-30min` relative format: PHP's date parser
    reads a bare `-30m` as a timezone offset (not minutes), which would put
    the window start in the future and fail the `from < to` guard (422).
    """
    response = api.get(
        f"/api/v1/devices/{device_id}/telemetry?from=-30min&to=now&resolution=1s"
    )
    return any(point.get("field") == field for point in response.get("points", []))
