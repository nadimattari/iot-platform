"""Mock LoRaWAN driver: a fake ChirpStack Application Server MQTT integration.

The real ChirpStack publishes each uplink to
`application/{application_id}/device/{dev_eui}/event/up` as a JSON envelope
whose decoded payload lives in `object`. The fake connects to the broker with
the chirpstack service account (the same ACLs the real AS integration uses) and
publishes the same envelope, so the payload flows through ingestion exactly like
a live uplink — resolved to the platform device via its claimed `dev_eui`.
"""

from datetime import UTC, datetime

from .mqtt import publish_samples


def publish_uplinks(
    *,
    host: str,
    port: int,
    username: str,
    password: str,
    app_id: str,
    dev_eui: str,
    samples: list[dict],
) -> None:
    """Publish each sample as a ChirpStack uplink envelope for `dev_eui`."""
    topic = f"application/{app_id}/device/{dev_eui}/event/up"
    envelopes = [
        {
            "applicationID": app_id,
            "applicationName": "iot-platform",
            "deviceName": "e2e-lorawan",
            "devEUI": dev_eui,
            "fPort": 10,
            "fCnt": index + 1,
            "object": payload,
            "time": datetime.now(UTC).isoformat(),
        }
        for index, payload in enumerate(samples)
    ]
    publish_samples(
        host=host,
        port=port,
        username=username,
        password=password,
        topic=topic,
        samples=envelopes,
    )
