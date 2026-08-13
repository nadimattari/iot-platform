# Implementation Plan: Self-hosted IIoT Platform

## Overview

Deliver a single-tenant IIoT platform deployed per customer VPS via Docker Compose: MQTT + LoRaWAN
(ChirpStack v4) + Modbus TCP + HTTP ingestion, PostgreSQL + TimescaleDB telemetry, JWT auth, and a Vue 3 + PrimeVue 
live dashboard. 9 containers behind Caddy + Mercure.

**Status:** Tasks 1-20 complete (Phases 0-2): Docker stack, TimescaleDB bootstrap, auth (JWT), device CRUD
+ provisioning, MQTT broker with per-device ACLs, Caddy + Mercure edge, ingestion (MQTT subscriber,
HTTP ingest, Modbus TCP poller, LoRaWAN uplink) → `telemetry_points`, the telemetry read API (`/telemetry`,
`/last`, `/status`), the ChirpStack v4 LoRaWAN network server (EU868, gateway bridge UDP 1700, MQTT
integration, admin UI), LoRaWAN confirmed downlinks with command lifecycle tracking (`event/txack` →
`sent`, `event/ack` → `acked`, timeout → `failed`), a unified command API (`POST /devices/{id}/commands`
routing by protocol to MQTT `devices/{id}/down` or ChirpStack, `GET /commands` history), real-time
Mercure events (ingestion publishes per-device telemetry to `/devices/{id}`, Symfony publishes command
status to `/devices/{id}/commands`, subscriber JWT enforced), the insights API
(`GET /insights/summary?group_id=`, `GET /insights/timeseries?device_id=&bucket=`) over the 1m/1h/1d
continuous aggregates, and the Vue 3 + PrimeVue dashboard foundation (typed API client with token
refresh, login/logout, protected routes, layout shell, served by Caddy at `/dashboard`).
Tasks 21-25 pending
(device list/detail, charts + insights pages, hardening).

## Architecture Decisions

- **Caddy is the only internet-facing service** (80/443 + UDP 1700 for LoRaWAN gateways); broker and DB never exposed directly.
- **JWT validation is stateless**: Node auth issues Ed25519 tokens, exposes JWKS; Symfony and ingestion validate with a cached public key (no per-request auth round-trips).
- **Per-device MQTT credentials** with Mosquitto ACLs scoping each device to `devices/{deviceId}/#`.
- **Ingestion owns all telemetry writes**; Symfony owns relational CRUD. Single writer per table avoids conflicts.
- **LoRaWAN downlink via ChirpStack v4 MQTT integration** (`command/down` topic, `devEui`/`confirmed`/`fPort`/`data|object`).
- **Insights via TimescaleDB continuous aggregates** (1m/1h/1d) — no separate ETL job.
- **EU868 region** for Mauritius / Indian Ocean customers.

## Task List

### Phase 0: Foundations

#### Task 1: Monorepo scaffold + Compose skeleton
**Description:** Create the repo layout (`deploy/`, `services/`, `db/`, `tests/`, `docs/`) and a docker-compose that boots all 9 services with healthchecks, networks, volumes, and `.env.example`. Containers run placeholder images/`sleep` until real apps land in later tasks.

**Acceptance criteria:**
- [x] `docker compose up -d` boots all services; `docker compose ps` shows them running
- [x] `.env.example` documents every secret (DB, broker, Mercure, JWT, ChirpStack) with no real values committed
- [x] Networks isolate internal traffic (broker/db not reachable from host ports)

**Verification:**
- [x] `docker compose up -d && docker compose ps` — all containers `Up`
- [x] `docker compose config` validates clean

**Dependencies:** None

**Files likely touched:** `deploy/docker-compose.yml`, `deploy/.env.example`, `deploy/docker-compose.test.yml`, `.gitignore`, repo root layout

**Estimated scope:** Medium (3-5 files)

#### Task 2: Database bootstrap (TimescaleDB)
**Description:** Init script creates databases `iiot` and `chirpstack`, enables TimescaleDB, creates `telemetry` schema with `telemetry_points` hypertable and `telemetry_raw`, and installs migration tooling (Doctrine Migrations for the Symfony service, plain SQL for ChirpStack schema ownership).

**Acceptance criteria:**
- [x] Fresh Postgres container creates both DBs; `telemetry_points(time, device_id, field, value, type, quality)` hypertable + indexes exist
- [x] Continuous aggregate definitions (1m/1h/1d) created as part of migration baseline
- [x] Migration runner works from within the device-mgmt container

**Verification:**
- [x] `docker compose up db` then `psql -c '\d telemetry_points'` shows hypertable
- [x] `php bin/console doctrine:migrations:status` reports up-to-date

**Dependencies:** Task 1

**Files likely touched:** `db/init/*.sql`, `services/device-mgmt/migrations/*`, `deploy/docker-compose.yml`

**Estimated scope:** Medium (3-5 files)

#### Task 3: Auth service skeleton (Node/Fastify)
**Description:** Fastify + TypeScript service with Dockerfile, config, health endpoint, DB migrations for `users` and `refresh_tokens`, Ed25519 key generation on first boot, and admin user seeded from env.

**Acceptance criteria:**
- [x] Container boots, healthcheck passes, connects to `iiot` DB
- [x] Ed25519 keypair generated once, persisted in volume; public key served at `GET /auth/jwks`
- [x] Admin account (email/password from env) seeded idempotently, password bcrypt-hashed

**Verification:**
- [x] `curl /health` → 200; `curl /auth/jwks` → JWK
- [x] `npm test` passes (auth service unit tests)

**Dependencies:** Task 2

**Files likely touched:** `services/auth/{Dockerfile,package.json,tsconfig.json}`, `services/auth/src/**`, `services/auth/migrations/*`, `deploy/docker-compose.yml`

**Estimated scope:** Medium

#### Task 4: Auth API (login / refresh / logout / me)
**Description:** Implement token lifecycle: `POST /auth/login` (access 15 min + rotating refresh 30 days), `POST /auth/refresh` (rotate, detect reuse), `POST /auth/logout` (revoke), `GET /auth/me`. Refresh tokens stored hashed.

**Acceptance criteria:**
- [x] Login returns signed access JWT + refresh token; invalid credentials → 401
- [x] Refresh rotates the token and revokes the old one; reused refresh token revokes the whole family
- [x] Logout revokes the presented refresh token

**Verification:**
- [x] `npm test` (auth flow tests) passes
- [x] Manual: login → refresh → logout via curl; reuse of rotated token rejected

**Dependencies:** Task 3

**Files likely touched:** `services/auth/src/routes/*`, `services/auth/src/services/*`, `services/auth/test/*`

**Estimated scope:** Medium

### Checkpoint A (after Tasks 1-4)
- [ ] Full stack boots; auth login/refresh/logout verified via curl
- [ ] JWKS endpoint reachable through Caddy
- [ ] Review with human before proceeding

### Phase 1: Device Management + Broker

#### Task 5: Device-mgmt skeleton (Symfony 7 + FrankenPHP)
**Description:** Symfony service with FrankenPHP worker Dockerfile, Doctrine connection to `iiot`, config from env, and a JWT-authentication guard that validates Ed25519 tokens against the cached JWKS (from the auth service).

**Acceptance criteria:**
- [x] Container boots; `GET /api/v1/health` → 200
- [x] Requests with valid JWT pass; missing/invalid JWT → 401; JWKS cached and refreshed
- [x] `php bin/phpunit` runs a basic smoke test

**Verification:**
- [x] `docker compose up device-mgmt` + curl with/without token
- [x] `php bin/console lint:container` passes

**Dependencies:** Tasks 2, 4

**Files likely touched:** `services/device-mgmt/{Dockerfile,composer.json}`, `services/device-mgmt/config/*`, `services/device-mgmt/src/Security/*`

**Estimated scope:** Medium

#### Task 6: Device CRUD API
**Description:** `device_groups`, `devices`, `device_profiles` entities + migrations; REST endpoints for CRUD, claim (attach `dev_eui`/protocol), enable/disable, list with pagination and protocol filter.

**Acceptance criteria:**
- [x] Create/list/get/update/delete device via `/api/v1/devices`; protocol enum enforced [mqtt, lorawan, modbus, http]
- [x] Claim associates `dev_eui` (unique) for LoRaWAN or generates per-device credentials for MQTT/HTTP
- [x] Device provisioning generates `api_key_hash`; plaintext shown once

**Verification:**
- [x] `php bin/phpunit` device tests pass
- [x] Manual: full device lifecycle via curl

**Dependencies:** Task 5

**Files likely touched:** `services/device-mgmt/src/Entity/*`, `src/Controller/*`, `src/Service/*`, `migrations/*`

**Estimated scope:** Medium

#### Task 7: MQTT broker + per-device credentials
**Description:** Mosquitto config with auth + ACLs; device-mgmt provisions broker credentials (generate password, append to `mosquitto.passwd`, ACL rule `devices/{deviceId}/#`) when a device is created/claimed; remove on delete. ChirpStack gets its own broker user.

**Acceptance criteria:**
- [x] Anonymous MQTT access denied; each device can publish/subscribe only its own `devices/{deviceId}/#`
- [x] Creating a device makes its credential immediately usable; deleting removes it
- [x] ChirpStack connects with its own credentials

**Verification:**
- [x] `mosquitto_pub` with device credential succeeds on own topic, fails on another's
- [x] Integration test: create device via API → publish → subscribe OK

**Dependencies:** Task 6

**Files likely touched:** `deploy/mqtt/mosquitto.conf`, `deploy/mqtt/acl`, `services/device-mgmt/src/Service/BrokerProvisioner.php`

**Estimated scope:** Medium

#### Task 8: Caddy routing + Mercure + static serving
**Description:** Caddyfile routing `/auth/*` → auth, `/api/v1/*` → device-mgmt, `/chirpstack/*` → ChirpStack (later), `/dashboard` → static SPA image, plus Mercure hub enabled with publisher/private JWTs and topic ACLs.

**Acceptance criteria:**
- [x] Each path proxies to the right service; SPA placeholder serves at `/dashboard`
- [x] Mercure hub responds to SSE subscription with a valid subscriber JWT
- [x] TLS auto-provisioned from env domain; works behind HTTP for local dev

**Verification:**
- [x] curl each route; `curl -H "Accept: text/event-stream"` on a Mercure topic returns a stream
- [x] `docker compose up` brings Caddy up healthy

**Dependencies:** Tasks 1, 4, 7

**Files likely touched:** `deploy/caddy/Caddyfile`, `deploy/caddy/mercure/*`, `deploy/docker-compose.yml`

**Estimated scope:** Medium

### Checkpoint B (after Tasks 5-8)
- [ ] Register a device via API → credential works on the broker (`mosquitto_pub`)
- [ ] Auth + device endpoints reachable through Caddy; Mercure SSE streams
- [ ] Review with human before proceeding

### Phase 2: Ingestion + Telemetry

#### Task 9: Ingestion service skeleton (Python/FastAPI)
**Description:** FastAPI + asyncio service with Dockerfile, env config, health endpoint, and structured asyncpg connection pool to `iiot` DB.

**Acceptance criteria:**
- [x] Container boots; `GET /health` → 200
- [x] Config loaded from env; no secrets in code
- [x] `pytest` runs

**Verification:**
- [x] `docker compose up ingestion`; curl health; `pytest`

**Dependencies:** Tasks 2, 5

**Files likely touched:** `services/ingestion/{Dockerfile,pyproject.toml}`, `services/ingestion/app/{main.py,config.py,db.py}`

**Estimated scope:** Small

#### Task 10: MQTT subscriber → normalize → TSDB
**Description:** asyncio MQTT client subscribes to `devices/{id}/up`, `modbus/{id}/up`, `application/+/device/+/event/up`; normalizer maps raw payloads to `TelemetryPoint`; writer batch-inserts to `telemetry_points` (+ `telemetry_raw` audit) with backpressure and no blocking of the MQTT loop.

**Acceptance criteria:**
- [x] Uplink on any subscribed topic yields rows in `telemetry_points` within 1s
- [x] Unknown/unparseable payloads logged and quarantined, never crash the loop
- [x] Batched writes (asyncpg, `COPY` for bursts) survive a 1k-message burst

**Verification:**
- [x] `pytest` normalizer/writer tests pass
- [x] Integration: publish MQTT sample → row in TSDB

**Dependencies:** Tasks 7, 9

**Files likely touched:** `services/ingestion/app/{mqtt.py,normalizer.py,writer.py}`, `tests/*`

**Estimated scope:** Medium

#### Task 11: HTTP ingest endpoint
**Description:** `POST /ingest/http/{deviceId}` accepting JSON payloads, authenticated by device API key (constant-time compare of hash), validated against the device profile schema.

**Acceptance criteria:**
- [x] Valid key + payload → 202, row in TSDB; invalid/missing key → 401
- [x] Payload validated against `device_profiles.field_defs`; bad payload → 422
- [x] Reuses the same normalizer/writer as Task 10

**Verification:**
- [x] `pytest` auth + validation tests pass
- [x] Manual: `curl -H "X-API-Key: ..." -d '{...}'` → row appears

**Dependencies:** Tasks 6, 10

**Files likely touched:** `services/ingestion/app/routes/ingest.py`, `app/schemas.py`

**Estimated scope:** Small

#### Task 12: Modbus TCP poller
**Description:** Async Modbus master polls `modbus_register_config` per device on independent intervals; decoded samples flow through the normalizer to TSDB and `modbus/{deviceId}/up`.

**Acceptance criteria:**
- [x] Registers read correctly per datatype/byteorder/scale for each configured device
- [x] Poll intervals are per-device/per-register; a dead device doesn't stall others
- [x] Register config CRUD (`GET/PUT /devices/{id}/registers`) drives live poller behavior

**Verification:**
- [x] `pytest` register-decoding tests pass (fixtures for int32/float32/uint16 + byte orders)
- [x] Manual: point poller at a mock Modbus server → samples in TSDB

**Dependencies:** Tasks 6, 10

**Files likely touched:** `services/ingestion/app/{modbus.py,decoder.py}`, `services/device-mgmt/src/Controller/RegisterController.php`

**Estimated scope:** Large (5-8 files) → split into poller + decoder if needed

#### Task 13: Telemetry read API (Symfony)
**Description:** `GET /devices/{id}/telemetry?from&to&resolution`, `GET /devices/{id}/last`, `GET /devices/{id}/status` — read TSDB, bucket to resolution, compute last_seen online state.

**Acceptance criteria:**
- [x] Series query with resolution bucketing returns correct min/max/avg/count per bucket
- [x] `/last` returns latest value per field; `/status` reflects last_seen vs heartbeat window
- [x] Query for 30 days of a few hundred devices at 1m resolution < 300ms

**Verification:**
- [x] `php bin/phpunit` query tests pass
- [x] Manual/perf: seeded dataset, measure query time

**Dependencies:** Tasks 6, 10

**Files likely touched:** `services/device-mgmt/src/Controller/TelemetryController.php`, `src/Service/TelemetryReader.php`

**Estimated scope:** Medium

### Checkpoint C (after Tasks 9-13)
- [ ] MQTT sample + HTTP POST both land in TSDB and appear via `/telemetry`
- [ ] Modbus poller produces data from a mock server
- [ ] Review with human before proceeding

### Phase 3: ChirpStack + LoRaWAN

#### Task 14: ChirpStack v4 container (EU868)
**Description:** ChirpStack `4.x` service with `chirpstack.toml` (EU868, shared Mosquitto, own DB schema, Redis), Gateway Bridge for UDP 1700, and admin UI exposed via Caddy. ChirpStack v4 has no sub-path support, so the UI gets its own hostname (`CHIRPSTACK_SITE_ADDR`, dev `http://chirpstack.localhost`).

**Acceptance criteria:**
- [x] ChirpStack boots against shared Postgres/Redis/Mosquitto; UI reachable on its own hostname (`chirpstack.localhost`)
- [x] Region EU868; gateway bridge listens on UDP 1700
- [x] MQTT integration enabled (`[integration.mqtt]`); uplinks visible on `application/{id}/device/{devEui}/event/up`

**Verification:**
- [x] `docker compose up chirpstack`; log in to UI (admin/admin initially), change creds
- [x] Join a real or simulated gateway; observe uplink events on broker (verified with `deploy/chirpstack/scripts/lorasim.py`: OTAA join + uplink, `event/join` + `event/up` observed on the broker)

**Notes (operational learnings):**
- ChirpStack's diesel migrations require extensions it does not create itself: `pg_trgm`, `hstore`, `pgcrypto` (and `citext`) — added to `db/init/01-databases.sh`; the `chirpstack` role must own/`CREATE` on the `public` schema.
- `chirpstack-gateway-bridge`'s `-c` flag takes a file, not a directory (unlike `chirpstack`); it uses the default config path instead.
- Docker drops published host ports for services attached only to internal-only networks; gateway-bridge and rest-api attach to the `frontend` network so UDP 1700 / 8090 bind on the host.
- v4 REST (`chirpstack-rest-api:8090`) exposes no login; auth is via an API key (`chirpstack create-api-key`). The web UI logs in via the internal gRPC service (`api.InternalService/Login`); admin password change uses `api.UserService/UpdatePassword`.

**Dependencies:** Tasks 2, 7, 8

**Files likely touched:** `deploy/chirpstack/{chirpstack.toml,region_eu868.toml,gateway-bridge/*}`, `deploy/chirpstack/scripts/lorasim.py`, `deploy/docker-compose.yml`, `deploy/caddy/Caddyfile`, `deploy/mqtt/acl`, `db/init/01-databases.sh`

**Estimated scope:** Medium

#### Task 15: LoRaWAN uplink ingestion
**Description:** Ingestion consumes ChirpStack uplinks (`application/+/device/+/event/up`), resolves `dev_eui` → platform device, decodes payload (ChirpStack `object` when codec configured, else raw), normalizes into TSDB.

**Acceptance criteria:**
- [x] Uplink for a claimed `dev_eui` lands in TSDB under the platform device id
- [x] Unknown `dev_eui` logged, not crashed, not written
- [x] Raw bytes (base64 `data`) stored in `telemetry_raw` for replay/audit

**Verification:**
- [x] `pytest` LoRaWAN normalization tests pass (109 total)
- [x] Integration: fake ChirpStack uplink published → TSDB row (`raw` = decoded FRMPayload bytes, points from `object`); also full E2E via `lorasim.py` → ChirpStack NS → broker → ingestion → TSDB (device `70b3d5499e320001` claimed to `lorawan-a`)

**Notes (operational learnings):**
- ChirpStack v4's real `event/up` envelope nests `devEUI` under `deviceInfo` (not top-level) and has no `object` unless a codec is configured — the dev_eui is taken from the topic, and a codec-less uplink correctly produces 0 points while still archiving the raw FRMPayload.
- The `telemetry_raw` `raw BYTEA` column was added via `ALTER TABLE ... ADD COLUMN IF NOT EXISTS` on the running DB (init scripts only run on fresh databases).

**Dependencies:** Tasks 10, 14

**Files likely touched:** `services/ingestion/app/{lorawan.py,normalizer.py,writer.py}`, `db/init/02-telemetry.sql`

**Estimated scope:** Medium

#### Task 16: LoRaWAN downlink + ack
**Description:** Symfony publishes downlink to `application/{id}/device/{devEui}/command/down` via the broker (`id` UUID for correlation, `devEui`, `confirmed`, `fPort`, `data`/`object`); tracks `event/ack` (device ACK or timeout) and `event/txack` (gateway accepted transmission) to update command status. Note: ChirpStack v4 has **no** `event/downlink` — only `up`, `join`, `ack`, `txack`, `log`, `status`, `location`; ACK/TxACK carry `queueItemId` matching the downlink command `id`.

**Acceptance criteria:**
- [x] Enqueue downlink via API → message appears on ChirpStack topic with correct format (`id`/`devEui`/`confirmed`/`fPort`/`data`|`object`)
- [x] `event/ack` updates command status to `acked` (or `failed` on timeout); `event/txack` → `sent`
- [x] Base64 `data` or decoded `object` supported

**Verification:**
- [x] `php bin/phpunit` downlink payload tests pass (82 tests, 308 assertions, 0 failures)
- [x] Integration: enqueue → observe ChirpStack queue via UI
- [x] Live E2E: confirmed downlink via `POST /devices/{id}/downlink` → `deploy/chirpstack/scripts/lorasim.py` receives + decrypts payload (`01020304050607`), ACKs → `event/txack` + `event/ack` consumed by `device-mgmt-consumer` → command status `sent` → `acked`
- [x] Consumer regression: php-mqtt v2.x subscription callbacks pass raw string content (not a `Message` object); callback no longer type-hints `Message`, covered by `ConsumeDownlinkEventsCommandTest`

**Dependencies:** Tasks 14, 15, 7

**Files likely touched:** `services/device-mgmt/src/Service/DownlinkService.php`, `src/Message/*`, `src/Consumer/*`

**Estimated scope:** Medium

#### Task 17: Unified command API
**Description:** Single `POST /devices/{id}/commands` handling MQTT down (`devices/{id}/down`) and LoRaWAN (via DownlinkService); `commands` table records status; `GET /commands` lists history; status updates via broker ACK consumers.

**Acceptance criteria:**
- [x] One endpoint sends commands over both transports with correct topic/payload
- [x] Command lifecycle visible: pending → sent → acked/failed
- [x] Disabled/unknown device → 4xx

**Verification:**
- [x] `php bin/phpunit` command tests pass (112 tests, 417 assertions, 0 failures)
- [x] Integration: MQTT mock device ACKs → status updates
- [x] Live E2E MQTT: `POST /devices/{id}/commands` → `devices/{id}/down` `{"id","payload"}` → mock device echoes id on `devices/{id}/ack` → `device-mgmt-consumer-acks` sets status `acked`
- [x] Live E2E LoRaWAN: same endpoint routes to ChirpStack `command/down`, lorasim ACKs → status `acked`
- [x] `GET /commands?device_id=&status=&page=&limit=` returns paginated history; no auth → 401

**Dependencies:** Tasks 7, 10, 16

**Files likely touched:** `services/device-mgmt/src/Controller/CommandController.php`, `src/Service/CommandService.php`, `src/Message/*`

**Estimated scope:** Medium

### Checkpoint D (after Tasks 14-17)
- [x] LoRaWAN uplink → TSDB; downlink reaches ChirpStack queue; ACK updates status
- [x] Commands work for MQTT and LoRaWAN via one API
- [x] Review with human before proceeding

### Phase 4: Real-time + Insights + Dashboard

#### Task 18: Real-time events (Mercure)
**Description:** Ingestion publishes normalized events to Mercure topic `/devices/{deviceId}` (publisher JWT, shared secret from env); Symfony publishes command/status events; subscribers authorized by topic ACL.

**Acceptance criteria:**
- [x] New telemetry for a device appears on its SSE topic in < 1s
- [x] Command status changes push to the dashboard topic
- [x] Subscription denied without valid subscriber JWT

**Verification:**
- [x] Integration: publish MQTT → SSE event received on subscribed topic
- [x] Security: no token → 401 on Mercure subscribe

**Dependencies:** Tasks 8, 10, 17

**Files likely touched:** `services/ingestion/app/mercure.py`, `services/device-mgmt/src/Service/EventPublisher.php`, `deploy/caddy/Caddyfile`

**Estimated scope:** Small

#### Task 19: Insights (continuous aggregates + API)
**Description:** 1m/1h/1d continuous aggregates (baseline from Task 2); `GET /insights/summary?group_id=` (per-field min/max/avg/count) and `GET /insights/timeseries?device_id=&bucket=`.

**Acceptance criteria:**
- [x] Aggregates refresh automatically; summary/timeseries return correct values from them
- [x] Queries stay < 300ms on 30 days × few hundred devices
- [x] Out-of-range dates return empty, not errors

**Verification:**
- [x] `php bin/phpunit` insights tests pass
- [x] Perf check on seeded data

**Dependencies:** Tasks 2, 13

**Files likely touched:** `services/device-mgmt/src/Controller/InsightsController.php`, `db/migrations/*`, `services/device-mgmt/src/Service/InsightsReader.php`

**Estimated scope:** Medium

#### Task 20: Dashboard foundation (Vue 3 + PrimeVue)
**Description:** Vite + Vue 3 + Pinia + PrimeVue app: typed API client (from OpenAPI), router, auth store, login page, layout shell, static image served by Caddy.

**Acceptance criteria:**
- [x] Login/logout works against auth service; token stored securely (memory/localStorage + refresh)
- [x] PrimeVue theme/layout renders; `/dashboard` routes protected
- [x] SPA container built from source, served by Caddy

**Verification:**
- [x] `npm run build` clean; `npm run test:unit` passes
- [x] Manual: login via UI → lands on dashboard shell

**Dependencies:** Tasks 8, 20 contract defined against API (Task 6)

**Files likely touched:** `services/dashboard/{package.json,vite.config.ts}`, `src/{router,stores,api,views/Login.vue}`, `deploy/docker-compose.yml`

**Estimated scope:** Large (5-8 files) → split scaffolding vs pages if needed

#### Task 21: Dashboard device list + detail
**Description:** Device list (pagination, protocol filter, online status), device detail with provisioning/claim form, enable/disable, live values updated via Mercure SSE subscription.

**Acceptance criteria:**
- [x] Device list/detail render from API; create/claim/enable/disable work from UI
- [x] Live values update in place on SSE event, no page reload
- [x] Online/offline state derived from `/status`

**Verification:**
- [x] `npm run test:unit` (component tests) passes
- [x] Manual: create device, publish data, watch value update live

**Dependencies:** Tasks 13, 18, 20

**Files likely touched:** `services/dashboard/src/views/Devices.vue`, `src/views/DeviceDetail.vue`, `src/components/*`, `src/stores/devices.ts`

**Estimated scope:** Large → split list vs detail if needed

#### Task 22: Dashboard charts + insights pages
**Description:** Telemetry chart view (time range, resolution, field picker) and insights pages (summary cards, timeseries) using PrimeVue Chart/ECharts.

**Acceptance criteria:**
- [x] Chart queries `/telemetry` with selected range/resolution and renders correctly
- [x] Insights summary/timeseries pages render aggregated data
- [x] Empty states and loading states handled

**Verification:**
- [x] `npm run test:unit` passes
- [x] Manual: seeded data renders across ranges

**Dependencies:** Tasks 19, 21

**Files likely touched:** `services/dashboard/src/views/Telemetry.vue`, `src/views/Insights.vue`, `src/components/TimeSeriesChart.vue`

**Estimated scope:** Medium

### Checkpoint E (after Tasks 18-22)
- [x] Live value on dashboard within 1s of a device publish (all protocols)
- [x] Charts + insights pages render from real data
- [ ] Review with human before proceeding

### Phase 5: Hardening + Delivery

#### Task 23: Deployment hardening
**Description:** Healthcheck/restart policies on all services, volume-backed secrets, TLS via Caddy auto, log rotation, automated backups (pg_dump + TimescaleDB), upgrade docs, and a provisioning runbook (DNS, ports, firewall incl. UDP 1700).

**Acceptance criteria:**
- [x] `docker compose restart` recovers cleanly; all healthchecks green
- [x] Backup/restore procedure documented and scripted
- [x] Firewall/port requirements documented (80/443, UDP 1700)

**Verification:**
- [x] Kill a container → it restarts healthy; restore from backup into fresh stack
- [ ] Runbook followed on a clean VPS end-to-end (deferred to Task 25)

**Dependencies:** All Phase 0-4

**Files likely touched:** `deploy/docker-compose.yml`, `deploy/backup/*`, `docs/*`, `deploy/runbook.md`

**Estimated scope:** Medium

#### Task 24: E2E test suite
**Description:** Compose test profile spins up the stack with mock devices for all 4 protocols; asserts ingest → TSDB → API → dashboard (SSE) flow; CI job runs it.

**Acceptance criteria:**
- [ ] Mock MQTT, Modbus (pymodbus server), HTTP, and LoRaWAN (fake ChirpStack uplink) drivers exist
- [ ] E2E asserts telemetry visible in API and SSE stream
- [ ] Runs green in CI on push/PR

**Verification:**
- [ ] `docker compose -f deploy/docker-compose.test.yml up --exit-code-from e2e` passes
- [ ] CI badge green

**Dependencies:** All Phase 0-4

**Files likely touched:** `tests/e2e/*`, `deploy/docker-compose.test.yml`, `.github/workflows/*`

**Estimated scope:** Large → split per protocol if needed

#### Task 25: Acceptance validation
**Description:** Run every success criterion from the spec on a fresh VPS (or parity local env) and record results.

**Acceptance criteria:**
- [ ] Fresh VPS: clone + `up -d` + DNS → dashboard on HTTPS, no manual setup
- [ ] All 4 protocol devices ingest; live value < 1s; telemetry query < 300ms; command round-trip works
- [ ] All success criteria documented as verified (or explicitly deferred)

**Verification:**
- [ ] Runbook executed on clean VPS; checklist signed off

**Dependencies:** Tasks 23, 24

**Files likely touched:** `docs/acceptance.md`, `docs/runbook.md`

**Estimated scope:** Small

## Risks and Mitigations

| Risk                                                         | Impact  | Mitigation                                                                           |
|--------------------------------------------------------------|---------|--------------------------------------------------------------------------------------|
| ChirpStack v4 config/compat drift (MQTT topics, toml schema) | Med     | Pin image version; MQTT integration verified in T14-T16; isolate in its own DB/Redis |
| Modbus decoder bugs (datatypes/byteorder/scale)              | Med     | Fixture-driven tests in T12; real-device validation checkpoint                       |
| TimescaleDB query performance at target load                 | Med     | Continuous aggregates from T2; perf gate in T13/T19 before UI                        |
| Mercure/SSE reliability on long-lived connections            | Low-Med | Topic ACLs, heartbeat config, reconnect logic in SPA                                 |
| Multi-protocol payload normalization drift                   | Med     | Single canonical `TelemetryPoint` + profile-validated schemas from T10               |
| Fresh-VPS deployment friction                                | Med     | Dockerized everything + runbook tested in T25                                        |
| Backup/restore correctness with TimescaleDB                  | Med     | Scripted + restored-to-fresh-stack test in T23                                       |

## Parallelization Opportunities

- **Safe to parallelize:** Tasks 5 (Symfony skeleton) and 9 (Python skeleton) after Task 2; Tasks 11, 12, 15 after Task 10; Task 20 scaffolding after API contracts (Tasks 6, 13).
- **Must be sequential:** DB bootstrap (T2) → everything; broker provisioning (T7) → all MQTT-dependent ingestion; ChirpStack (T14) → LoRaWAN work.
- **Needs coordination:** API contracts (OpenAPI for auth + device-mgmt) must be frozen before dashboard (T20-22) work starts in parallel.
