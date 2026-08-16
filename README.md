# Self-hosted IIoT Platform

[![E2E](https://github.com/nadimattari/iot-platform/actions/workflows/e2e.yml/badge.svg)](https://github.com/nadimattari/iot-platform/actions/workflows/e2e.yml)

A single-tenant, self-hosted industrial IoT platform: connect, monitor, and
control devices over **MQTT, LoRaWAN, Modbus TCP, and HTTP/REST**, with live
dashboards, time-series insights, and command/downlink control.

Each customer deploys the full stack on their own VPS via Docker Compose.
There is no SaaS multi-tenancy.

> **Status: in development.** Phases 0-3 and Task 25 acceptance validation
> (on an RPi4 parity host — see `docs/acceptance.md`) are implemented; only
> external HTTPS + the upgrade-path pull remain, deferred to the VPS. The
> full Docker stack boots locally, MQTT / Modbus TCP / HTTP / LoRaWAN data lands
> in TimescaleDB behind JWT auth, a telemetry read API (`/telemetry`, `/last`,
> `/status`) serves it, and a ChirpStack v4 network server (EU868) with a
> gateway bridge ingests uplinks (raw FRMPayload archived to `telemetry_raw`)
> and confirms confirmed downlinks. A unified command API
> (`POST /devices/{id}/commands`, `GET /commands`) sends commands over both
> transports with lifecycle tracking (`sent` → `acked`). Ingestion publishes
> per-device telemetry to a Mercure SSE hub (`/devices/{id}`, <1s) and Symfony
> pushes command/status events to `/devices/{id}/commands`; subscribers must
> present a JWT (no token → 401). An insights API
> (`GET /insights/summary?group_id=`, `GET /insights/timeseries?device_id=&bucket=`)
> serves per-field min/max/avg/count from the 1m/1h/1d continuous aggregates
> (<50ms on 30 days × 300 devices). A Vue 3 + PrimeVue SPA is served by Caddy
> at `/dashboard`: typed API client with transparent token refresh, JWT
> login/logout, protected routes, and a dashboard layout shell. The device
> list/detail views are live: a shared Mercure SSE hub client mints a
> subscriber JWT via `/auth/mercure-token`, multiplexes `/devices/{id}` and
> `/devices/{id}/commands` subscriptions, and updates online status, live
> values, and command lifecycle (`sent` → `acked`) in place without polling.
> The telemetry view charts `/devices/{id}/telemetry` with time-range,
> resolution, and field pickers; the insights view renders per-group summary
> cards (min/max/avg/count) and per-device multi-field timeseries from
> `/insights/summary` and `/insights/timeseries`, with a device-group picker
> backed by a new `GET /api/v1/groups` endpoint; both views handle loading and
> empty states. Deployment hardening (Task 23) adds healthchecks + log rotation
> to every container, automated TimescaleDB-aware backup/restore scripts
> (`deploy/backup/`), and a provisioning runbook (`deploy/runbook.md`) covering
> DNS, firewall/ports (80/443, UDP 1700), Caddy auto-TLS, upgrades, and
> restore-onto-a-fresh-stack. The E2E suite (Task 24) spins up the stack in a
> compose test profile (`deploy/docker-compose.test.yml`) with mock devices for
> all four protocols and asserts ingest → TSDB → API → Mercure(SSE) in CI
> (`.github/workflows/e2e.yml`). The VPS acceptance run remains.
> See [`specs/iiot-platform.md`](specs/iiot-platform.md) and
> [`specs/iiot-platform-plan.md`](specs/iiot-platform-plan.md).

## Stack

| Container         | Tech                        | Role                                                                |
|-------------------|-----------------------------|---------------------------------------------------------------------|
| Caddy + Mercure   | Caddy 2.x                   | Edge reverse proxy, TLS, static SPA serving, SSE hub                |
| Auth              | Node.js 22 + Fastify        | User management, JWT issue/refresh/revoke                           |
| Device management | Symfony 7 + FrankenPHP      | Device/provisioning REST API, reads TSDB                            |
| Ingestion         | Python 3.12 + FastAPI       | MQTT subscribe, Modbus TCP poll, LoRaWAN, HTTP ingest → TimescaleDB |
| Database          | PostgreSQL 16 + TimescaleDB | Relational + time-series                                            |
| Redis             | Redis 7                     | Required by ChirpStack                                              |
| MQTT broker       | Eclipse Mosquitto 2         | Shared device/ChirpStack bus                                        |
| LoRaWAN           | ChirpStack v4 (EU868)       | Network Server + Application Server                                 |
| Dashboard         | Vue 3 + PrimeVue            | Live device views, charts, insights                                 |

## Features

- **Device management** — register, provision, claim, enable/disable devices;
  per-device MQTT credentials with broker ACLs
- **Multi-protocol ingestion** — MQTT uplinks, Modbus TCP polling, LoRaWAN via
  ChirpStack, and HTTP/REST (JSON) pushes, normalized into one telemetry model
- **Data management** — telemetry in a TimescaleDB hypertable with continuous
  aggregates for insights (1m/1h/1d)
- **Application management** — live dashboard with real-time updates via
  Mercure (SSE), time-series charts, and insight pages
- **Command control** — downlinks for MQTT devices and LoRaWAN (via ChirpStack
  MQTT integration), with acknowledgement tracking
- **Authentication** — JWT via a dedicated Node auth service; stateless
  Ed25519 signature validation across services

## Architecture

```
LoRa devices ──UDP 1700──▶ gateway ──▶ ChirpStack ──MQTT──▶ Mosquitto
MQTT devices ──────────────────────────────────────────────▶ Mosquitto
Modbus TCP devices ◀──poll──▶ ingestion (Modbus master)
HTTP devices ──POST /ingest/http──▶ ingestion
                                         ▼
                   ingestion (Python) ──▶ TimescaleDB
                                             │
                  Mercure (SSE) ◀── events ──┘
Browser ──▶ Caddy ──/dashboard─────▶ SPA
               ├────/auth/*────────▶ auth (Node)
               ├────/api/v1/*──────▶ device-mgmt (Symfony)
                └──chirpstack.localhost──▶ ChirpStack UI
```

Caddy is the only internet-facing service (ports 80/443, plus UDP 1700 for
LoRaWAN gateways); the broker and database are never exposed directly.

## Repository Layout

```
specs/        → design spec + implementation plan (Tasks 1-25 checked)
deploy/       → docker-compose, Caddyfile, mqtt/ broker config, chirpstack/, mock-modbus/
services/     → auth (Node/Fastify), device-mgmt (Symfony), ingestion (Python), dashboard (Vue 3 SPA)
db/           → init scripts (databases, telemetry hypertable + continuous aggregates)
tests/        → integration + e2e (scaffolds)
docs/         → agent/IDE setup guides
```

## Getting Started

```bash
cp deploy/.env.example deploy/.env   # fill in secrets
docker compose -f deploy/docker-compose.yml up -d
```

See the [spec](specs/iiot-platform.md) for objectives and success criteria,
and the [implementation plan](specs/iiot-platform-plan.md) for the 25-task breakdown across 6 phases.
