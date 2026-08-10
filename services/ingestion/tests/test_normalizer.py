import json
import uuid
from datetime import UTC, datetime

import pytest

from app.normalizer import NormalizeError, normalize


def test_mqtt_uplink_flat_object_maps_every_field():
    device_id = str(uuid.uuid4())
    uplink = normalize(
        f"devices/{device_id}/up",
        b'{"time":"2026-08-10T10:00:00Z","temperature":22.5,"humidity":45,"on":true}',
    )

    assert uplink.protocol == "mqtt"
    assert uplink.device_id == device_id
    assert uplink.dev_eui is None
    fields = {p.field: p for p in uplink.points}
    assert fields["temperature"].value == 22.5
    assert fields["temperature"].type == "float"
    assert fields["humidity"].value == 45.0
    assert fields["humidity"].type == "int"
    assert fields["on"].value == 1.0
    assert fields["on"].type == "bool"
    assert all(p.device_id == device_id for p in uplink.points)
    assert uplink.points[0].time == datetime(2026, 8, 10, 10, 0, tzinfo=UTC)


def test_defaults_time_to_now_when_absent():
    device_id = str(uuid.uuid4())
    before = datetime.now(UTC)
    uplink = normalize(f"devices/{device_id}/up", b'{"temperature": 1.5}')
    after = datetime.now(UTC)
    assert before <= uplink.time <= after


def test_numeric_strings_are_accepted():
    device_id = str(uuid.uuid4())
    uplink = normalize(f"devices/{device_id}/up", b'{"power": "12.5"}')
    assert [p for p in uplink.points if p.field == "power"][0].value == 12.5


def test_non_numeric_and_nested_values_are_skipped():
    device_id = str(uuid.uuid4())
    payload = json.dumps({"sensor": "ok", "nested": {"a": 1}, "temp": 3.0}).encode()
    uplink = normalize(f"devices/{device_id}/up", payload)
    assert [p.field for p in uplink.points] == ["temp"]


def test_modbus_topic_parses_device_id_and_protocol():
    device_id = str(uuid.uuid4())
    uplink = normalize(f"modbus/{device_id}/up", b'{"temperature": 18.0}')
    assert uplink.protocol == "modbus"
    assert uplink.device_id == device_id


def test_lorawan_topic_extracts_dev_eui_and_object_fields():
    dev_eui = "0012345678ABCDEF"
    uplink = normalize(
        f"application/1/device/{dev_eui}/event/up",
        b'{"devEUI":"0012345678ABCDEF","object":{"temperature":21.0,"rssi":-70}}',
    )
    assert uplink.protocol == "lorawan"
    assert uplink.device_id is None
    assert uplink.dev_eui == dev_eui
    fields = {p.field for p in uplink.points}
    assert fields == {"temperature", "rssi"}


def test_lorawan_without_object_skips_metadata_keys():
    dev_eui = "0012345678ABCDEF"
    payload = json.dumps({
        "devEUI": dev_eui,
        "applicationName": "demo",
        "rxInfo": [],
        "time": "2026-08-10T10:00:00Z",
        "temperature": 19.0,
    }).encode()
    uplink = normalize(f"application/1/device/{dev_eui}/event/up", payload)
    fields = {p.field for p in uplink.points}
    assert fields == {"temperature"}


def test_unknown_topic_is_quarantined():
    with pytest.raises(NormalizeError):
        normalize("some/random/topic", b"{}")


def test_invalid_device_id_in_topic_is_quarantined():
    with pytest.raises(NormalizeError):
        normalize("devices/not-a-uuid/up", b'{"temperature": 1}')


def test_non_json_payload_is_quarantined():
    with pytest.raises(NormalizeError):
        normalize(f"devices/{uuid.uuid4()}/up", b"garbage")


def test_non_object_json_is_quarantined():
    with pytest.raises(NormalizeError):
        normalize(f"devices/{uuid.uuid4()}/up", b"[1,2,3]")


def test_unparseable_time_is_quarantined():
    with pytest.raises(NormalizeError):
        normalize(f"devices/{uuid.uuid4()}/up", b'{"time":"not-a-date","temperature":1}')


def test_payload_with_no_fields_yields_valid_empty_uplink():
    uplink = normalize(f"devices/{uuid.uuid4()}/up", b'{"time":"2026-08-10T10:00:00Z"}')
    assert uplink.points == ()
