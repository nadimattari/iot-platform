"""Task 11: HTTP ingest endpoint.

Covers the pure pieces (api-key verification, profile schema validation,
HTTP payload normalization) and the endpoint behaviour via TestClient with
faked db/writer state (401/202/413/422).
"""

import hashlib
import json
import uuid

import pytest
from fastapi.testclient import TestClient

from app.config import Settings
from app.devices import (
    DeviceIdentity,
    fetch_device,
    fetch_profile_schema,
    verify_api_key,
)
from app.main import create_app
from app.normalizer import normalize_payload
from app.schema import ProfileSchema, SchemaValidationError

GOOD_KEY = "dk_test-valid-key-123"
GOOD_HASH = hashlib.sha256(GOOD_KEY.encode()).hexdigest()


def device_row(*, enabled=True, api_key_hash=GOOD_HASH, metadata=None):
    return {
        "id": str(uuid.uuid4()),
        "enabled": enabled,
        "api_key_hash": api_key_hash,
        "metadata": metadata or {},
    }


class FakePool:
    def __init__(self, dev_row, field_defs=None):
        self.dev_row = dev_row
        self.field_defs = field_defs
        self.queries = []

    async def fetchrow(self, sql, *args):
        self.queries.append((sql, args))
        if "FROM devices" in sql:
            return self.dev_row
        if "FROM device_profiles" in sql:
            return {"field_defs": self.field_defs} if self.field_defs is not None else None
        return None


class FakeDb:
    def __init__(self, pool):
        self.pool = pool


class FakeWriter:
    def __init__(self):
        self.puts = []

    async def put(self, uplink):
        self.puts.append(uplink)


def make_client(dev_row, field_defs=None):
    settings = Settings(
        _env_file=None,
        pg_host="127.0.0.1",
        pg_port=1,
        pool_min_size=1,
        pool_max_size=1,
        pg_connect_timeout_secs=0,
        mqtt_enabled=False,
    )
    client = TestClient(create_app(settings))
    client.__enter__()
    client.app.state.db = FakeDb(FakePool(dev_row, field_defs))
    client.app.state.writer = FakeWriter()
    return client


def teardown_client(client):
    client.__exit__(None, None, None)


# --- verify_api_key ---------------------------------------------------------

def test_verify_api_key_accepts_correct_key():
    identity = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {})
    assert verify_api_key(identity, GOOD_KEY) is True


def test_verify_api_key_rejects_wrong_key():
    identity = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {})
    assert verify_api_key(identity, "dk_wrong") is False


def test_verify_api_key_rejects_missing_or_none_hash():
    identity = DeviceIdentity(str(uuid.uuid4()), True, None, {})
    assert verify_api_key(identity, GOOD_KEY) is False


def test_verify_api_key_rejects_unknown_device_without_fast_path():
    assert verify_api_key(None, GOOD_KEY) is False
    assert verify_api_key(None, None) is False


def test_verify_api_key_rejects_missing_key():
    identity = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {})
    assert verify_api_key(identity, None) is False


# --- fetch_device -----------------------------------------------------------

async def test_fetch_device_returns_identity():
    row = device_row()
    identity = await fetch_device(FakePool(row), row["id"])
    assert identity == DeviceIdentity(row["id"], True, GOOD_HASH, {})


async def test_fetch_device_normalizes_uuid_to_str():
    """asyncpg returns UUID columns as uuid.UUID; the identity must be a str
    so HTTP-ingested points serialize in the Mercure SSE payload."""
    raw = uuid.uuid4()
    row = device_row()
    row["id"] = raw
    identity = await fetch_device(FakePool(row), str(raw))
    assert identity.device_id == str(raw)
    assert isinstance(identity.device_id, str)


async def test_fetch_device_returns_none_when_missing_or_db_down():
    assert await fetch_device(FakePool(None), str(uuid.uuid4())) is None


async def test_fetch_device_parses_jsonb_metadata_string():
    dev = device_row(metadata='{"profile_id": "p1"}')
    identity = await fetch_device(FakePool(dev), dev["id"])
    assert identity.metadata == {"profile_id": "p1"}


async def test_fetch_device_looks_up_by_id():
    pool = FakePool(device_row())
    await fetch_device(pool, "019feb7d-0000-0000-0000-000000000000")
    sql, args = pool.queries[0]
    assert "FROM devices" in sql
    assert args == ("019feb7d-0000-0000-0000-000000000000",)


# --- fetch_profile_schema ---------------------------------------------------

async def test_fetch_profile_schema_resolves_metadata_profile_id():
    dev = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {"profile_id": "p1"})
    schema = await fetch_profile_schema(
        FakePool(device_row(), field_defs=[{"field": "temperature", "type": "float"}]),
        dev,
    )
    assert schema is not None
    assert schema.fields == {"temperature": "float"}


async def test_fetch_profile_schema_none_without_profile_reference():
    dev = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {})
    assert await fetch_profile_schema(FakePool(device_row()), dev) is None


async def test_fetch_profile_schema_none_for_unknown_profile():
    dev = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {"profile_id": "missing"})
    assert await fetch_profile_schema(FakePool(device_row(), field_defs=None), dev) is None


async def test_fetch_profile_schema_parses_jsonb_field_defs_string():
    dev = DeviceIdentity(str(uuid.uuid4()), True, GOOD_HASH, {"profile_id": "p1"})
    schema = await fetch_profile_schema(
        FakePool(
            device_row(),
            field_defs='[{"field": "temperature", "type": "float"}]',
        ),
        dev,
    )
    assert schema is not None
    assert schema.fields == {"temperature": "float"}


# --- ProfileSchema ----------------------------------------------------------

def test_profile_schema_builds_from_field_defs():
    schema = ProfileSchema.from_field_defs(
        [{"field": "temperature", "type": "float"}, {"field": "rpm", "type": "int"}]
    )
    assert schema.fields == {"temperature": "float", "rpm": "int"}


def test_profile_schema_ignores_unknown_types_and_malformed_entries():
    schema = ProfileSchema.from_field_defs(
        [
            {"field": "temperature", "type": "float"},
            {"field": "weird", "type": "regex"},
            {"not": "a dict"},
            {"field": "nope", "type": 42},
        ]
    )
    assert schema.fields == {"temperature": "float"}


def test_profile_schema_none_for_non_list_or_empty():
    assert ProfileSchema.from_field_defs({}) is None
    assert ProfileSchema.from_field_defs("nope") is None
    assert ProfileSchema.from_field_defs([{"field": "x", "type": "regex"}]) is None


def test_profile_schema_validate_passes_when_payload_matches():
    schema = ProfileSchema.from_field_defs(
        [{"field": "temperature", "type": "float"}, {"field": "rpm", "type": "int"}]
    )
    schema.validate({"temperature": 22.5, "rpm": "1200", "extra": "ignored"})


def test_profile_schema_validate_rejects_missing_field():
    schema = ProfileSchema.from_field_defs([{"field": "temperature", "type": "float"}])
    with pytest.raises(SchemaValidationError) as exc:
        schema.validate({"rpm": 100})
    assert "temperature" in exc.value.errors


def test_profile_schema_validate_rejects_wrong_type():
    schema = ProfileSchema.from_field_defs([{"field": "temperature", "type": "float"}])
    with pytest.raises(SchemaValidationError) as exc:
        schema.validate({"temperature": "hot"})
    assert "expected type 'float'" in exc.value.errors["temperature"]


def test_profile_schema_validate_accepts_bool_and_string_fields():
    schema = ProfileSchema.from_field_defs(
        [{"field": "on", "type": "bool"}, {"field": "status", "type": "string"}]
    )
    schema.validate({"on": True, "status": "running"})


# --- normalize_payload ------------------------------------------------------

def test_normalize_payload_protocol_is_http():
    device_id = str(uuid.uuid4())
    uplink = normalize_payload(device_id, {"temperature": 21.0})
    assert uplink.protocol == "http"
    assert uplink.device_id == device_id
    assert uplink.topic == f"http/{device_id}"
    assert [p.field for p in uplink.points] == ["temperature"]


def test_normalize_payload_skips_non_numeric_and_time():
    device_id = str(uuid.uuid4())
    uplink = normalize_payload(
        device_id, {"time": "2026-08-10T10:00:00Z", "status": "ok", "temp": 3}
    )
    assert [p.field for p in uplink.points] == ["temp"]
    assert uplink.points[0].device_id == device_id


# --- endpoint behaviour -----------------------------------------------------

def test_lifespan_exposes_db_and_writer_on_app_state():
    settings = Settings(
        _env_file=None,
        pg_host="127.0.0.1",
        pg_port=1,
        pool_min_size=1,
        pool_max_size=1,
        pg_connect_timeout_secs=0,
        mqtt_enabled=False,
    )
    with TestClient(create_app(settings)) as client:
        assert hasattr(client.app.state, "db")
        assert hasattr(client.app.state, "writer")


def test_ingest_202_writes_points():
    client = make_client(device_row())
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=json.dumps({"temperature": 22.5}),
        )
    finally:
        writer = client.app.state.writer
        teardown_client(client)
    assert res.status_code == 202
    body = res.json()
    assert body["accepted"] is True
    assert body["points"] == 1
    assert len(writer.puts) == 1
    assert writer.puts[0].points[0].field == "temperature"


def test_ingest_202_with_profile_passes_validation():
    field_defs = [{"field": "temperature", "type": "float"}, {"field": "humidity", "type": "int"}]
    client = make_client(device_row(metadata={"profile_id": "p1"}), field_defs=field_defs)
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=json.dumps({"temperature": 22.5, "humidity": 51}),
        )
    finally:
        teardown_client(client)
    assert res.status_code == 202


def test_ingest_401_without_key():
    client = make_client(device_row())
    try:
        res = client.post(f"/ingest/http/{client.app.state.db.pool.dev_row['id']}", content=b"{}")
    finally:
        teardown_client(client)
    assert res.status_code == 401
    assert res.json()["error"]["code"] == "UNAUTHORIZED"


def test_ingest_401_with_wrong_key():
    client = make_client(device_row())
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": "dk_wrong"},
            content=b"{}",
        )
    finally:
        teardown_client(client)
    assert res.status_code == 401


def test_ingest_401_for_unknown_device():
    client = make_client(None)
    try:
        res = client.post(
            f"/ingest/http/{uuid.uuid4()}",
            headers={"X-API-Key": GOOD_KEY},
            content=b"{}",
        )
    finally:
        teardown_client(client)
    assert res.status_code == 401


def test_ingest_401_for_disabled_device():
    client = make_client(device_row(enabled=False))
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=b"{}",
        )
    finally:
        teardown_client(client)
    assert res.status_code == 401


def test_ingest_401_for_non_uuid_device_id():
    client = make_client(device_row())
    try:
        res = client.post("/ingest/http/not-a-uuid", headers={"X-API-Key": GOOD_KEY}, content=b"{}")
    finally:
        teardown_client(client)
    assert res.status_code == 401


def test_ingest_422_for_non_json_body():
    client = make_client(device_row())
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=b"not json",
        )
    finally:
        teardown_client(client)
    assert res.status_code == 422
    assert res.json()["error"]["code"] == "VALIDATION_ERROR"


def test_ingest_422_for_non_object_body():
    client = make_client(device_row())
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=b"[1, 2, 3]",
        )
    finally:
        teardown_client(client)
    assert res.status_code == 422


def test_ingest_422_when_profile_schema_rejected():
    field_defs = [{"field": "temperature", "type": "float"}]
    client = make_client(device_row(metadata={"profile_id": "p1"}), field_defs=field_defs)
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=json.dumps({"temperature": "hot"}),
        )
    finally:
        teardown_client(client)
    assert res.status_code == 422
    assert res.json()["error"]["code"] == "VALIDATION_ERROR"
    assert "temperature" in res.json()["error"]["details"]


def test_ingest_413_for_oversized_body():
    client = make_client(device_row())
    try:
        res = client.post(
            f"/ingest/http/{client.app.state.db.pool.dev_row['id']}",
            headers={"X-API-Key": GOOD_KEY},
            content=b'{"pad": "' + b"x" * (65 * 1024) + b'"}',
        )
    finally:
        teardown_client(client)
    assert res.status_code == 413
