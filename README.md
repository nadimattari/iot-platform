# Self-hosted IIoT Platform

A single-tenant, self-hosted industrial IoT platform in the style of
[Beaver IoT](https://github.com/Milesight-IoT/beaver-iot): connect, monitor, and
control devices over **MQTT, LoRaWAN, Modbus TCP, and HTTP/REST**, with live
dashboards, time-series insights, and command/downlink control.

Each customer deploys the full stack on their own VPS via Docker Compose.
There is no SaaS multi-tenancy.

> **Status: planning.** The architecture is specified; implementation has not
> started. See [`specs/iiot-platform.md`](specs/iiot-platform.md) and
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
               └────/chirpstack/*──▶ ChirpStack UI
```

Caddy is the only internet-facing service (ports 80/443, plus UDP 1700 for
LoRaWAN gateways); the broker and database are never exposed directly.

## Repository Layout

```
specs/        → design spec + implementation plan
deploy/       → docker-compose, Caddyfile, broker/ChirpStack config (planned)
services/     → auth, device-mgmt, ingestion, dashboard (planned)
db/           → SQL bootstrap (planned)
tests/        → integration + e2e (planned)
docs/         → runbook, backup, acceptance (planned)
```

## Getting Started

See the [spec](specs/iiot-platform.md) for objectives and success criteria,
and the [implementation plan](specs/iiot-platform-plan.md) for the 25-task breakdown across 6 phases.
