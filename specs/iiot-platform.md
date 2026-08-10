# Spec: Self-hosted IIoT Platform

## Objective

A single-tenant, self-hosted IIoT platform that a customer deploys on their own VPS via Docker Compose. It connects, 
monitors, and controls devices over MQTT, LoRaWAN, Modbus TCP, and HTTP/REST (JSON), stores telemetry in PostgreSQL + 
TimescaleDB, and provides a live dashboard/insights UI.

This is a product to be delivered per customer: one Compose stack per deployment. There is no SaaS multi-tenancy.

### User stories / acceptance criteria

- As an operator I can log in (JWT auth) and manage devices (register, provision, enable/disable, delete).
- As an operator I can connect devices via any of the four protocols and see their live values on a dashboard without 
restarting the stack.
- As an operator I can view time-series history and aggregate insights for any device.
- As an operator I can send a command/downlink to a device and see whether it was acknowledged.
- Each customer deploys the whole thing with `docker compose up -d` on a bare VPS (DNS + ports 80/443 + 1700/UDP for
LoRaWAN gateways).

## Tech Stack

| Container   | Tech                                          | Role                                                                                                            |
|-------------|-----------------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| caddy       | Caddy 2.x                                     | Edge reverse proxy, TLS, serves dashboard SPA, hosts Mercure hub                                                |
| mercure     | Mercure (in Caddy)                            | SSE hub for real-time dashboard updates                                                                         |
| auth        | Node.js 22 + TypeScript (Fastify/Express)     | User management, JWT issue/refresh/revoke                                                                       |
| device-mgmt | FrankenPHP 1.x (PHP 8.3, worker mode)         | Device/provisioning/config REST API, reads TSDB aggregates                                                      |
| ingestion   | Python 3.12 + FastAPI + asyncio               | MQTT subscribe, Modbus TCP poll, LoRaWAN (ChirpStack MQTT), HTTP ingest; normalize + write TSDB; publish events |
| db          | PostgreSQL 16 + TimescaleDB                   | Relational (platform + ChirpStack schemas) + time-series                                                        |
| redis       | Redis 7                                       | Required by ChirpStack; optional ingestion buffering                                                            |
| mqtt        | Eclipse Mosquitto 2                           | MQTT bus shared by devices, ChirpStack, ingestion (ChirpStack-native, low footprint)                            |
| chirpstack  | chirpstack/chirpstack:4.x (latest v4)         | LoRaWAN Network Server + Application Server (merged in v4)                                                      |
| dashboard   | Vue 3 SPA + PrimeVue (built, served by Caddy) | Live device views, charts, insights                                                                             |

1. PHP service uses **Symfony 7 + FrankenPHP worker mode**.
2. Python service is **FastAPI**; SQL access via asyncpg; MQTT via asyncio-mqtt.
3. Node auth service is **Fastify + TypeScript** (lightweight; NestJS is the alternative).
4. Frontend is **Vue 3 + Vite + PrimeVue** (PrimeVue includes chart components; ECharts when heavy time-series needed).
5. **Mosquitto** as broker (ChirpStack's native MQTT; EMQX is the alternative if a web UI/ACL dashboard is needed).
6. TimescaleDB **continuous aggregates** compute insights rollups (1m/1h/1d); no separate ETL job.
7. Modbus is **TCP only** (Ethernet gateways); serial/RTU out of scope in v1.
8. One shared PostgreSQL instance with separate databases: `iiot`, `chirpstack`, and TimescaleDB `telemetry` schema in `iiot`.
9. No alerting/rules engine, no multi-tenancy, no device automation/workflows in v1.
10. LoRaWAN downlink goes through ChirpStack (via its MQTT downlink integration or gRPC API).

## Architecture / Data Flow

```
LoRa devices ──UDP 1700──▶ gateway ──chirpstack-gateway-bridge──▶ MQTT broker
                                                                       │
MQTT devices ─────────────────────────────────────────────────────────▶│
Modbus TCP devices ◀──poll──▶ ingestion (Modbus master)                │
HTTP devices ──POST /ingest/http──▶ ingestion                          │
                                                                       ▼
                                          ┌─────────────────────────────────────┐
                    ChirpStack ──MQTT────▶│  ingestion (Python)                 │
                                          │  subscribe/normalize/batch ──▶ TSDB │
                                          │  publish events ──▶ Mercure         │
                                          └─────────────────────────────────────┘
                                                      │
                            PostgreSQL+TimescaleDB ◀──┘ (hypertable + continuous aggregates)

Browser ──▶ Caddy──/dashboard─────▶ SPA  (SSE ◀── Mercure)
                ├──/auth/*────────▶ auth (Node)       ── issues/validates JWTs
                ├──/api/v1/*──────▶ device-mgmt (PHP) ── validates JWT, CRUD, reads TSDB
                └──/api/insights*─▶ device-mgmt (PHP) ── reads continuous aggregates
```

### MQTT topic design (shared broker)

| Topic                                           | Direction              | Description                              |
|-------------------------------------------------|------------------------|------------------------------------------|
| `devices/{deviceId}/up`                         | device → broker        | raw uplink from direct-MQTT device       |
| `devices/{deviceId}/down`                       | broker → device        | command payload to device                |
| `devices/{deviceId}/ack`                        | device → broker        | command acknowledgement                  |
| `modbus/{deviceId}/up`                          | ingestion → broker     | normalized Modbus sample                 |
| `application/{id}/device/{devEUI}/event/up`     | ChirpStack → broker    | LoRaWAN uplink (ChirpStack integration)  |
| `application/{id}/device/{devEUI}/command/down` | broker → ChirpStack    | LoRaWAN downlink                         |
| `platform/events`                               | ingestion/PHP → broker | normalized events for internal consumers |

- Devices authenticate with **per-device credentials** (API key); Mosquitto ACLs restrict each device to its own `devices/{deviceId}/#`.
- ChirpStack uses dedicated MQTT credentials.
- JWT is for **users**, never devices.

### Authentication (Node auth service)

- `POST /auth/login` → access JWT (15 min) + refresh token (30 days, stored hashed in `refresh_tokens`).
- `POST /auth/refresh`, `POST /auth/logout` (revokes), `GET /auth/me`.
- Ed25519 keypair; public key exposed via `GET /auth/jwks` so PHP and ingestion validate **statelessly** (cached, no per-request round-trip).
- Single admin account seeded at first boot; optional role field on user for future roles.
- The device-mgmt service and dashboard validate JWTs; Caddy enforces path routing only.

### Ingestion pipeline (Python)

1. **MQTT subscriber** (asyncio) consumes `devices/{id}/up`, `modbus/{id}/up`, `application/+/device/+/event/up`.
2. **Modbus TCP poller** reads configured registers per device, on independent intervals.
3. **HTTP ingest** FastAPI endpoint `POST /ingest/http/{deviceId}` (HMAC/API-key auth, JSON payload).
4. **Normalizer** maps each source into a canonical `TelemetryPoint(device_id, ts, field, value, type, quality)`.
5. **Writer** batch-inserts into TimescaleDB (asyncpg; `COPY` for bursts). Accepts small backpressure, never blocks the MQTT loop.
6. **Publisher** emits normalized events to Mercure topic `/devices/{deviceId}` for live dashboards.

## Data Model

### PostgreSQL `iiot` database

```
users(id, email UNIQUE, password_hash, role, created_at)
refresh_tokens(id, user_id FK, token_hash, expires_at, revoked_at, created_at)

device_groups(id, name)
devices(id, group_id FK NULL, name, protocol ENUM[mqtt,lorawan,modbus,http],
        dev_eui UNIQUE NULL, api_key_hash, metadata JSONB,
        enabled BOOL, last_seen_at, created_at)
device_profiles(id, name, field_defs JSONB)   -- blueprint of telemetry fields/types

modbus_register_config(id, device_id FK, name, unit_id, register_type,
        address, count, datatype, byteorder, scale, poll_interval_s)

commands(id, device_id FK, issued_by FK users, direction ENUM[down],
        payload JSONB, status ENUM[pending,sent,acked,failed],
        issued_at, acked_at)
```

### TimescaleDB (`telemetry` schema, hypertable)

```
telemetry_points(time TIMESTAMPTZ, device_id UUID, field TEXT,
                 value DOUBLE PRECISION, type TEXT, quality SMALLINT)
- partition by time (1 day chunks), index (device_id, time), (field, time)
- continuous aggregates: telemetry_1m, telemetry_1h, telemetry_1d
```

Design note: `telemetry_points` (normalized) chosen over `payload JSONB` for queryability of insights; raw JSONB retained in a companion `telemetry_raw` table for audit/replay.

### ChirpStack (own schema in shared Postgres + Redis)

Manage gateways/devices/tenants via the ChirpStack web UI (reachable through Caddy at `/chirpstack`). The platform links a ChirpStack device to a platform device by `dev_eui`.

## API Surface (device-mgmt, `/api/v1`, JWT-protected)

- `GET/POST/PUT/DELETE /devices` — CRUD, provision, enable/disable
- `POST /devices/{id}/claim` — associate dev_eui / credentials
- `GET /devices/{id}/telemetry?from=&to=&resolution=` — series from TSDB
- `GET /devices/{id}/last` — latest value per field
- `GET/PUT /devices/{id}/registers` — Modbus register config
- `POST /devices/{id}/commands` — send downlink/command; `GET /commands` — history/status
- `GET /devices/{id}/status` — online state (last_seen vs. heartbeat window)
- `GET /insights/summary?group_id=` — per-field min/max/avg/count (continuous aggregates)
- `GET /insights/timeseries?device_id=&bucket=` — bucketed series for charts

## Project Structure (monorepo)

```
deploy/
  docker-compose.yml
  .env.example
  caddy/Caddyfile            # routing, TLS, Mercure hub config
  chirpstack/                # chirpstack.toml, region, gateway bridge
  mqtt/                      # mosquitto.conf, ACLs, device credential provisioning
services/
  auth/                      # Node.js + TypeScript (Fastify)
  device-mgmt/               # Symfony 7 + FrankenPHP worker
  ingestion/                 # Python 3.12 + FastAPI
  dashboard/                 # Vue 3 SPA
db/
  init/                      # SQL bootstrap (users seed, timescale extension, chirpstack db)
tests/
  integration/               # cross-container tests (compose test profile)
  e2e/                       # mock-device publishes MQTT → assert dashboard/API
docs/
```

## Commands

```bash
# Dev — build & run whole stack
docker compose -f deploy/docker-compose.yml up -d --build
docker compose logs -f ingestion device-mgmt

# Per-service dev
cd services/auth         && npm run dev
cd services/device-mgmt  && frankenphp run            # worker mode, serves public/index.php
cd services/ingestion    && uvicorn app.main:app --reload
cd services/dashboard    && npm run dev

# Tests
cd services/auth         && npm test
cd services/device-mgmt  && php bin/phpunit
cd services/ingestion    && pytest
cd services/dashboard    && npm run test:unit
docker compose -f deploy/docker-compose.test.yml up --exit-code-from e2e   # integration/e2e
```

## Code Style

- **PHP (Symfony):** PSR-12, PHP CS Fixer, strict types, thin controllers (services handle logic), DTOs for API contracts, serialization via Symfony Serializer/API Platform conventions.
- **Python:** ruff + black, type hints everywhere, FastAPI schemas via Pydantic v2, asyncio only (no blocking calls in the loop).
- **Node/TS:** strict TypeScript, ESLint + Prettier, zod validation on inputs, no `any`.
- **Vue:** `<script setup>`, Composition API, Pinia for state, typed API client generated from an OpenAPI spec.
- Shared OpenAPI contract for `auth` and `device-mgmt` APIs; generated client types for the SPA.

## Testing Strategy

- **Unit:** per service — protocol normalizer, JWT logic, register config parsing, API handlers.
- **Integration (compose test profile):** start stack, publish a fake MQTT sample + POST /ingest/http, assert row in TSDB, assert `GET /devices/{id}/telemetry` returns it.
- **E2E:** mock device driver publishes MQTT/Modbus/HTTP; verify live value appears on dashboard via Mercure (SSE) and in insights endpoint.
- Coverage target: ≥80% on ingestion normalizer and auth service; ≥60% overall.

## Boundaries

- **Always:** run tests before commit; validate every input (JWT, API keys, payload schema); use TimescaleDB for telemetry; keep secrets in `.env` (never committed); pin image versions in compose; document protocol additions.
- **Ask first:** schema changes, adding a service/container, changing the broker, changing auth model, bumping ChirpStack major version.
- **Never:** commit secrets/keys; expose broker or DB to the internet directly (only via Caddy); disable MQTT authentication; store passwords or refresh tokens in plaintext; remove failing tests to pass CI.

## Success Criteria

- Fresh VPS: `git clone` + `docker compose up -d` + DNS → working dashboard on HTTPS. No manual DB/broker setup.
- A device of each protocol (MQTT, LoRaWAN, Modbus TCP, HTTP) can be registered, publishes data, and its live value appears on the dashboard in < 1s.
- Historical query `telemetry` from→to at 1m resolution returns in < 300ms for a few hundred devices × 30 days.
- Command round-trip: send → device ack → status visible in UI.
- All 9 containers come up healthy with a single `docker compose up -d`; `docker compose restart` recovers cleanly.

## Resolved Decisions

1. **Auth service:** Fastify + TypeScript (lightweight, low memory on customer VPS, strong JWT/refresh-token ecosystem).
2. **LoRaWAN downlink:** ChirpStack v4 MQTT integration — publish to `application/{id}/device/{devEui}/command/down` with `{devEui, confirmed, fPort, data|object}`; ACK/status via `event/ack` and `event/downlink`. Verified against v4.
3. **Region:** EU868 (Mauritius / Indian Ocean customers use the EU868 frequency plan).
4. **Dashboard container:** separate static image (nginx-less; served by Caddy), built at CI, not baked into the Caddy image.
