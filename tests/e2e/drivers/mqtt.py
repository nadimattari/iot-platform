"""Mock MQTT device driver.

Connects to the shared broker with the device's provisioned credentials
(username = device id, password = device API key) — the same ACLs a real device
hits — and publishes JSON uplinks on `devices/{device_id}/up`, which the
ingestion subscriber normalizes into telemetry points.
"""

import json
import time

import paho.mqtt.client as mqtt


def _connect(
    host: str,
    port: int,
    username: str,
    password: str,
    *,
    attempts: int = 6,
    connect_timeout: float = 10.0,
) -> mqtt.Client:
    """Connect, retrying until the broker confirms the session.

    A fresh device credential is provisioned into the broker's password file on
    device creation and picked up via SIGHUP; the retries absorb that small
    window so the driver never races the provisioning reload.
    """
    last_error: Exception | None = None
    for attempt in range(attempts):
        client = mqtt.Client(
            mqtt.CallbackAPIVersion.VERSION2,
            client_id=f"e2e-{username[:8]}-{attempt}",
        )
        client.username_pw_set(username, password)
        try:
            client.connect(host, port, keepalive=30)
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            time.sleep(2)
            continue
        client.loop_start()
        try:
            deadline = time.monotonic() + connect_timeout
            while not client.is_connected():
                if time.monotonic() > deadline:
                    raise ConnectionError("broker did not confirm the session")
                time.sleep(0.1)
            return client
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            client.loop_stop()
            client.disconnect()
            time.sleep(2)
    raise ConnectionError(
        f"MQTT connect failed after {attempts} attempts to {host}:{port}: {last_error}"
    )


def publish_samples(
    *,
    host: str,
    port: int,
    username: str,
    password: str,
    topic: str,
    samples: list[dict],
    delay: float = 0.2,
) -> None:
    """Publish each payload to `topic` and wait for its QoS 1 PUBACK."""
    client = _connect(host, port, username, password)
    try:
        for payload in samples:
            info = client.publish(topic, json.dumps(payload), qos=1)
            info.wait_for_publish(timeout=10)
            time.sleep(delay)
    finally:
        client.loop_stop()
        client.disconnect()
