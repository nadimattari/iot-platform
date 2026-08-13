#!/usr/bin/env python3
"""End-to-end suite for the IIoT platform (Task 24).

Runs against a fresh stack started by `deploy/docker-compose.test.yml` and
asserts the full ingest -> TimescaleDB -> API -> Mercure(SSE) path for all four
device protocols, each driven by a mock device:

  - MQTT: a fake device publishes `devices/<id>/up` with its provisioned broker
    credentials (username = device id, password = API key);
  - Modbus: the ingestion poller reads `mock-modbus` (raw FC03 TCP server) and
    publishes `modbus/<id>/up` itself;
  - HTTP: `POST /ingest/http/<id>` with the device API key;
  - LoRaWAN: a fake ChirpStack publishes
    `application/<app>/device/<devEui>/event/up` on the broker.

For each device it then asserts the telemetry shows up in the read API
(`/last` and `/telemetry`) and in the dashboard's live SSE stream (the Mercure
topic `/devices/<id>`). Exits 0 only when every check passes, so
`docker compose ... up --exit-code-from e2e` reflects the suite result.
"""

import os
import sys
import time

import drivers.http as http_driver
import drivers.lorawan as lorawan_driver
import drivers.mqtt as mqtt_driver
from e2e_lib import Api, SseStream, has_series_point, wait_for_event, wait_for_field

BASE_URL = os.environ["E2E_BASE_URL"].rstrip("/")
MQTT_HOST = os.environ["E2E_MQTT_HOST"]
MQTT_PORT = int(os.environ["E2E_MQTT_PORT"])
ADMIN_EMAIL = os.environ["AUTH_ADMIN_EMAIL"]
ADMIN_PASSWORD = os.environ["AUTH_ADMIN_PASSWORD"]
CHIRPSTACK_APP_ID = os.environ["CHIRPSTACK_APPLICATION_ID"]
CHIRPSTACK_MQTT_USERNAME = os.environ["CHIRPSTACK_MQTT_USERNAME"]
CHIRPSTACK_MQTT_PASSWORD = os.environ["CHIRPSTACK_MQTT_PASSWORD"]
LORAWAN_DEV_EUI = os.environ.get("E2E_LORAWAN_DEV_EUI", "70b3d5499e320001")

# The Modbus poller reloads register config on a 15s cadence, so its first
# sample can arrive later than the push-based protocols.
MODBUS_TIMEOUT = 60
PROTOCOL_TIMEOUT = 30

results: list[tuple[str, bool]] = []


def check(name: str, ok: bool, detail: str = "") -> None:
    results.append((name, ok))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}" + (f" - {detail}" if detail else ""))


def create_device(api: Api, name: str, protocol: str, metadata: dict | None = None):
    """Create a device and return (serialized device, api key or None)."""
    body = {"name": name, "protocol": protocol, "metadata": metadata or {}}
    response = api.post("/api/v1/devices", body)
    return response["device"], response.get("api_key")


def wait_for_login(api: Api, email: str, password: str, timeout: float = 90) -> bool:
    """Bootstrap login with retry.

    Docker's embedded DNS and Caddy can take a moment to become reachable
    right after the e2e container starts (transient EAI_AGAIN / ECONNREFUSED).
    Retry rather than aborting on the first failed attempt.
    """
    deadline = time.monotonic() + timeout
    last_error: str | None = None
    while time.monotonic() < deadline:
        try:
            api.login(email, password)
            return True
        except Exception as exc:  # noqa: BLE001
            last_error = str(exc)
            time.sleep(1)
    print(
        f"[FATAL] admin login failed after {timeout:.0f}s: {last_error}",
        file=sys.stderr,
    )
    return False


def main() -> int:
    api = Api(BASE_URL)
    if not wait_for_login(api, ADMIN_EMAIL, ADMIN_PASSWORD):
        return 1
    check("admin login via /auth/login", True)

    mercure = api.get("/auth/mercure-token")
    check("Mercure subscriber token minted", "mercure_token" in mercure)

    stream = SseStream(
        f"{BASE_URL}/.well-known/mercure?topic=%2Fdevices%2F%7Bid%7D",
        {
            "Authorization": f"Bearer {mercure['mercure_token']}",
            "Accept": "text/event-stream",
        },
    )
    stream.start()

    # --- provision one device per protocol -------------------------------
    mqtt_device, mqtt_key = create_device(api, "e2e-mqtt", "mqtt")
    modbus_device, _ = create_device(
        api,
        "e2e-modbus",
        "modbus",
        {"modbus_host": "mock-modbus", "modbus_port": 5020, "modbus_unit_id": 1},
    )
    http_device, http_key = create_device(api, "e2e-http", "http")
    lorawan_device, _ = create_device(api, "e2e-lorawan", "lorawan")

    check("MQTT device created with api key", bool(mqtt_key))
    check("HTTP device created with api key", bool(http_key))

    api.post(f"/api/v1/devices/{lorawan_device['id']}/claim", {"dev_eui": LORAWAN_DEV_EUI})
    check("LoRaWAN device claimed to dev_eui", True)

    api.put(
        f"/api/v1/devices/{modbus_device['id']}/registers",
        {
            "registers": [
                {"name": "temperature", "address": 0, "datatype": "float32",
                 "byteorder": "big", "scale": 1.0, "interval_secs": 2},
                {"name": "pressure", "address": 2, "datatype": "uint16",
                 "byteorder": "big", "scale": 1.0, "interval_secs": 2},
                {"name": "counter", "address": 4, "datatype": "uint32",
                 "byteorder": "big", "scale": 1.0, "interval_secs": 2},
            ]
        },
    )
    check("Modbus registers provisioned", True)

    # --- publish samples through each mock driver -------------------------
    mqtt_driver.publish_samples(
        host=MQTT_HOST,
        port=MQTT_PORT,
        username=mqtt_device["id"],
        password=mqtt_key,
        topic=f"devices/{mqtt_device['id']}/up",
        samples=[
            {"temperature": 22.5, "humidity": 55.0},
            {"temperature": 22.7, "humidity": 55.5},
            {"temperature": 22.9, "humidity": 56.0},
        ],
    )

    http_driver.push_samples(
        base_url=BASE_URL,
        device_id=http_device["id"],
        api_key=http_key,
        samples=[
            {"temperature": 21.0, "pressure": 1013.25},
            {"temperature": 21.2, "pressure": 1013.5},
        ],
    )

    lorawan_driver.publish_uplinks(
        host=MQTT_HOST,
        port=MQTT_PORT,
        username=CHIRPSTACK_MQTT_USERNAME,
        password=CHIRPSTACK_MQTT_PASSWORD,
        app_id=CHIRPSTACK_APP_ID,
        dev_eui=LORAWAN_DEV_EUI,
        samples=[
            {"temperature": 24.1, "humidity": 61.0},
            {"temperature": 24.3, "humidity": 61.5},
        ],
    )

    # --- assert telemetry in the SSE stream and the read API --------------
    mqtt_sse = wait_for_event(stream, mqtt_device["id"], "temperature", PROTOCOL_TIMEOUT)
    check("MQTT: telemetry event on SSE stream", mqtt_sse is not None)
    mqtt_last = wait_for_field(api, mqtt_device["id"], "temperature", PROTOCOL_TIMEOUT)
    check(
        "MQTT: /last returns temperature",
        mqtt_last is not None and abs(mqtt_last["value"] - 22.9) < 1.0,
        f"value={mqtt_last['value'] if mqtt_last else None}",
    )
    check("MQTT: /telemetry series has temperature", has_series_point(api, mqtt_device["id"], "temperature"))

    http_sse = wait_for_event(stream, http_device["id"], "pressure", PROTOCOL_TIMEOUT)
    check("HTTP: telemetry event on SSE stream", http_sse is not None)
    http_last = wait_for_field(api, http_device["id"], "pressure", PROTOCOL_TIMEOUT)
    check(
        "HTTP: /last returns pressure",
        http_last is not None and abs(http_last["value"] - 1013.5) < 1.0,
        f"value={http_last['value'] if http_last else None}",
    )
    check("HTTP: /telemetry series has pressure", has_series_point(api, http_device["id"], "pressure"))

    lorawan_sse = wait_for_event(stream, lorawan_device["id"], "humidity", PROTOCOL_TIMEOUT)
    check("LoRaWAN: telemetry event on SSE stream", lorawan_sse is not None)
    lorawan_last = wait_for_field(api, lorawan_device["id"], "humidity", PROTOCOL_TIMEOUT)
    check(
        "LoRaWAN: /last returns humidity",
        lorawan_last is not None and abs(lorawan_last["value"] - 61.5) < 1.0,
        f"value={lorawan_last['value'] if lorawan_last else None}",
    )
    check("LoRaWAN: /telemetry series has humidity", has_series_point(api, lorawan_device["id"], "humidity"))

    modbus_sse = wait_for_event(stream, modbus_device["id"], "pressure", MODBUS_TIMEOUT)
    check("Modbus: telemetry event on SSE stream", modbus_sse is not None)
    modbus_last = wait_for_field(api, modbus_device["id"], "pressure", MODBUS_TIMEOUT)
    check(
        "Modbus: /last returns pressure",
        modbus_last is not None and abs(modbus_last["value"] - 1013.0) < 1.0,
        f"value={modbus_last['value'] if modbus_last else None}",
    )
    check("Modbus: /telemetry series has temperature", has_series_point(api, modbus_device["id"], "temperature"))

    stream.stop()
    print("=" * 56)
    failed = [name for name, ok in results if not ok]
    if failed:
        print(f"E2E FAILED ({len(failed)} check(s)): {', '.join(failed)}")
        return 1
    print(f"E2E PASSED ({len(results)} checks)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
