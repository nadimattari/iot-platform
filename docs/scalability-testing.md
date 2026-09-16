# Scalability and Load Testing (no physical devices)

How to validate ingestion throughput and query performance against the full stack
without LoRaWAN gateways, Modbus devices, or any hardware. Targets **acceptance
SC5**: historical query `/telemetry?from=-30d&to=now&resolution=1m` returns in
**< 300 ms** for a few hundred devices × 30 days. Evidence from RPi4 parity:
110 ms for a 30-day 1m query (433k points) and 92 ms for `/last`
([acceptance.md](acceptance.md#success-criteria-checklist)).

## Prerequisites

- Running dev stack: `cp deploy/.env.example deploy/.env && cd deploy && docker compose up -d --build`
- `curl`, `jq` on the host; `psql` available via Docker
- Optionally a `docker build` of the lorasim image (for LoRaWAN load)

## 1. Build the lorasim image

`deploy/chirpstack/scripts/lorasim.py` is a LoRaWAN 1.0.4 OTAA device+gateway
simulator. The companion Dockerfile packages it with `paho-mqtt` and
`pycryptodome`:

```bash
docker build -t lorasim deploy/chirpstack/scripts/
```

Verify: `docker run --rm lorasim --help` should print the usage.

## 2. Provision N devices

Devices must exist in both the platform API (for device-mgmt) and ChirpStack
(for LoRaWAN). For non-LoRaWAN protocols only the platform API is needed.

### Platform API (all protocols)

```bash
# Login
TOKEN=$(curl -s -X POST http://localhost/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"change-me"}' | jq -r .access_token)

# Create devices (loop)
for i in $(seq 1 200); do
  curl -s -X POST http://localhost/api/v1/devices \
    -H "Authorization: Bearer $TOKEN" \
    -H 'Content-Type: application/json' \
    -d "{\"name\":\"load-mqtt-$i\",\"protocol\":\"mqtt\"}" | jq '{id:.id,api_key:.api_key}'
done
```

The `api_key` in the response is the per-device MQTT password and HTTP
`X-API-Key` header value.

### ChirpStack (LoRaWAN only)

Open the ChirpStack UI at `http://localhost:8080` (admin/admin). For each
LoRaWAN device, create a Device in the target Application with a known
`AppKey` (e.g. `00000000000000000000000000000001`). The `DevEUI` shown in
ChirpStack becomes the `--dev-eui` argument to lorasim; the `AppKey` is
`--app-key`.

## 3. Generate load per protocol

### LoRaWAN (lorasim)

Each lorasim container simulates one device. Run N containers with unique
`--dev-eui`, `--app-key`, and optionally distinct `--gateway-id`, `--sf`,
and `--channel` to simulate multiple gateways and spreading factors:

```bash
# Example: 50 LoRaWAN devices across 5 gateways, 10 channels
for i in $(seq 1 50); do
  gw_id="01020304050607$(printf '%02x' $((i % 5 + 1)))"
  sf=$((10 + i % 3))          # spread factor 10, 11, or 12
  chan=$((i % 10))             # channel 0-9
  docker run -d --rm \
    --name "lorasim-$i" \
    --network iiot-platform_backend \
    lorasim \
    --broker mqtt:1883 \
    --username chirpstack \
    --password change-me \
    --dev-eui "$(printf '70b3d5499e32%04x' $i)" \
    --app-eui 0000000000000000 \
    --app-key "0000000000000000000000000000000$(printf '%x' $i)" \
    --gateway-id "$gw_id" \
    --sf "$sf" \
    --channel "$chan" \
    --uplinks 10
done
```

For steady-rate traffic, add a wrapper that loops the container on a
timer (e.g. `while true; do docker run --rm ... --uplinks 1; sleep 5; done`).

### MQTT devices

Publish directly to the broker with per-device credentials (username =
device id, password = api_key):

```bash
for i in $(seq 1 200); do
  ID=$(curl -s http://localhost/api/v1/devices -H "Authorization: Bearer $TOKEN" | jq -r ".devices[$((i-1))].id")
  API_KEY=$(curl -s http://localhost/api/v1/devices -H "Authorization: Bearer $TOKEN" | jq -r ".devices[$((i-1))].api_key")
  # Publish a sample every 5 seconds in background
  while true; do
    mosquitto_pub -h localhost -p 1883 \
      -u "$ID" -P "$API_KEY" \
      -t "devices/$ID/up" \
      -m "{\"t\":$(python3 -c 'import random;print(round(random.uniform(15,35),2))')}"
    sleep 5
  done &
done
```

### HTTP devices

```bash
for i in $(seq 1 200); do
  ID=...   # from the provisioning loop
  KEY=...  # api_key
  while true; do
    curl -s -X POST "http://localhost/ingest/http/$ID" \
      -H "X-API-Key: $KEY" \
      -H 'Content-Type: application/json' \
      -d "{\"pressure\":$(python3 -c 'import random;print(round(random.uniform(950,1050),2))')}"
    sleep 5
  done &
done
```

### Modbus TCP

Register configs against the `mock-modbus` container (FC03, port 5020)
via `PUT /api/v1/devices/{id}/registers`. The ingestion poller reads
each device's registers at the configured `poll_interval_s` and publishes
`modbus/{id}/up`. Vary `poll_interval_s` (e.g. 10–30s) to control
throughput per device.

## 4. Backfill a 30-day history (query performance test)

Running ingest for 30 days is not practical. Ingestion honours an optional
`time` field in MQTT and HTTP payloads
([normalizer.py](../services/ingestion/app/normalizer.py)), so you can post
backdated timestamps:

```bash
# Backfill 30 days × 1 sample every 5 minutes for device $ID
ID=...
KEY=...
for t in $(python3 -c "
import datetime, itertools, sys
base = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=30)
for i in itertools.count():
    print((base + datetime.timedelta(seconds=300*i)).isoformat())
    if (base + datetime.timedelta(seconds=300*(i+1))) > datetime.datetime.now(datetime.timezone.utc):
        break
"); do
  curl -s -X POST "http://localhost/ingest/http/$ID" \
    -H "X-API-Key: $KEY" \
    -H 'Content-Type: application/json' \
    -d "{\"time\":\"$t\",\"pressure\":$(python3 -c 'import random;print(round(random.uniform(950,1050),2))')}"
done
```

Run this for all devices before timing queries. Note: LoRaWAN and Modbus
payloads do **not** carry a user-settable `time` (Modbus stamps `now`;
LoRaWAN time comes from the envelope), so backfill only works via MQTT
and HTTP.

## 5. Measure

### Query latency

```bash
# SC5 metric: < 300 ms for 30 days @ 1m
time curl -s "http://localhost/api/v1/devices/$ID/telemetry?from=-30days&to=now&resolution=1m" | jq length

# Latest value latency
time curl -s "http://localhost/api/v1/devices/$ID/last" | jq .
```

### Ingestion throughput

```bash
docker compose -f deploy/docker-compose.yml logs -f ingestion   # watch for errors / backpressure
docker compose -f deploy/docker-compose.yml exec db \
  psql -U iiot -d iiot -c "SELECT count(*) FROM telemetry.telemetry_points;"
```

### Resource usage

```bash
docker stats --no-stream
```

## 6. Known ceilings

| Bottleneck                  | Limit                    | Configuration                                 |
|-----------------------------|--------------------------|-----------------------------------------------|
| Batch writer                | ≤ 500 rows / 0.2 s       | `WRITE_BATCH_SIZE`, `WRITE_BATCH_TIMEOUT` env  |
| Write queue backpressure    | 10 000 messages          | `WRITE_QUEUE_MAXSIZE` env (default 10 000)     |
| Mosquitto broker            | single-node, in-process  | no clustering in dev stack                     |
| ChirpStack NS               | single-node in-process   | EU868 duty-cycle not enforced by simulator     |
| DB connection pool          | 10 connections           | `POOL_MAX_SIZE` env (default 10)              |

On a Raspberry Pi 4 parity host (4 GB RAM, single SSD), the platform
sustained ingest from all four protocols simultaneously and met the
SC5 query target at full device count with reduced density.
A dense 5-min-interval load remains to be validated on a VPS
([acceptance.md](acceptance.md#success-criteria-checklist)).
