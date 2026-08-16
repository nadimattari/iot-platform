# Documentation Plan

A plan for the complete documentation set for the IIoT platform. Every
document is stored in the `specs/` folder, written in **simple British
English**, with **Mermaid diagrams** wherever a picture explains the flow
better than prose.

## Conventions

- **Language**: simple British English. Use British spellings (`organise`,
  `analyse`, `behaviour`, `serialise`, `acknowledged`). Keep sentences short;
  explain for someone new to the codebase, not the author.
- **Diagrams**: Mermaid only (`flowchart`, `sequenceDiagram`, `erDiagram`,
  `stateDiagram-v2`). Never ASCII art. Quote node labels containing `|`, `/`,
  `(`, `)` or `*`.
- **One concern per document.** Never duplicate content — cross-reference with
  relative links (`../specs/iiot-platform-api.md`, or `../../` as needed).
- **Source of truth stays in code.** Docs describe behaviour; the code,
  compose files and migrations are authoritative. Link to them.
- **Keep it current.** When a doc is written it must match the deployed stack;
  when code changes, update the relevant doc in the same change.

## File naming

All new documents follow `specs/iiot-platform-{topic}.md`. Existing files:

| File | Status |
|------|--------|
| `iiot-platform.md` | existing — design spec (SC1–SC9) |
| `iiot-platform-plan.md` | existing — implementation plan (Tasks 1–25) |
| `iiot-platform-erd.md` | existing — database ERD |
| `specs/README.md` | **new** — index linking every document |

## Document index

| # | Document | Purpose | Mermaid |
|---|----------|---------|---------|
| 1 | `README.md` (specs index) | Map of the whole doc set, reading order | — |
| 2 | `iiot-platform-overview.md` | What the platform is, top-level view | architecture overview |
| 3 | `iiot-platform-architecture.md` | Component + deployment architecture | flowchart, sequence |
| 4 | `iiot-platform-erd.md` | Database tables, relationships (done) | erDiagram |
| 5 | `iiot-platform-api.md` | REST API reference for all services | — |
| 6 | `iiot-platform-auth.md` | JWT, refresh rotation, API keys, MQTT creds | sequenceDiagram |
| 7 | `iiot-platform-data-flow.md` | Telemetry + command flows end-to-end | sequenceDiagram |
| 8 | `iiot-platform-telemetry.md` | TimescaleDB, normaliser, continuous aggregates | — |
| 9 | `iiot-platform-commands.md` | Downlink lifecycle, ack tracking | stateDiagram-v2 |
| 10 | `iiot-platform-lorawan.md` | ChirpStack integration (gateway-bridge, NS/AS, REST) | flowchart |
| 11 | `iiot-platform-mqtt.md` | Mosquitto topics, ACLs, credential provisioning | flowchart |
| 12 | `iiot-platform-deployment.md` | Compose stack, networks, ports, env vars, Caddy | flowchart |
| 13 | `iiot-platform-hosting.md` | Minimum server requirements; VPS, industrial RPi / edge, exposing the Pi | flowchart |
| 14 | `iiot-platform-security.md` | Security model and hardening | — |
| 15 | `iiot-platform-testing.md` | Test strategy, E2E suite, CI | — |
| 16 | `iiot-platform-glossary.md` | Terminology (uplink, downlink, cagg, LoRaWAN…) | — |

## Document details

### 1. `specs/README.md` — index

- Short description of the platform (two paragraphs).
- The table above, plus links to `iiot-platform.md` (design spec),
  `iiot-platform-plan.md` (plan), `../deploy/runbook.md` (operations) and
  `../README.md` (quick start).
- Suggested reading order: overview → architecture → data flow → api → then
  topic docs as needed.

### 2. `iiot-platform-overview.md` — system overview

- What the platform does: collect time-series telemetry from industrial
  devices over four protocols (MQTT, HTTP, Modbus TCP, LoRaWAN), store it in
  TimescaleDB, expose it through a REST API and a live dashboard.
- The four services (auth, device-mgmt, ingestion, dashboard) and what each
  owns. One short paragraph each.
- The four ingest protocols at a glance.
- Mermaid: small `flowchart LR` of the whole system (mirrors README
  architecture but in more detail with protocols).

Sources: `README.md`, `deploy/docker-compose.yml`, `specs/iiot-platform.md`.

### 3. `iiot-platform-architecture.md` — architecture

- Component diagram: all 15 containers, grouped by concern (web ingress,
  application, data, LoRaWAN, broker, auxiliaries).
- Network topology: `frontend`, `backend` (internal), published ports; the
  exact Caddy route table.
- Runtime architecture per service (process model, entrypoints, consumers).
- Data path summary (one paragraph + flowchart).
- Mermaid: `flowchart LR` with subgraphs per concern, plus a port/network
  table.

Sources: `deploy/docker-compose.yml`, `deploy/caddy/Caddyfile`,
`services/*/Dockerfile`, `services/device-mgmt/bin/*`.

### 4. `iiot-platform-erd.md` — database ERD (existing)

- Already written. Cross-link from index; update only if schema changes.

### 5. `iiot-platform-api.md` — API reference

One section per service, each with a method table and request/response shape:

- **auth** (`services/auth/src/routes/auth.ts`):
  `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`,
  `GET /auth/me`, `GET /auth/mercure-token`.
- **device-mgmt** (`services/device-mgmt/src/Controller/*.php`), prefix
  `/api/v1`:
  `GET /health`, `GET /auth/me`, `GET|POST /devices`, `GET|PUT|PATCH|DELETE
  /devices/{id}`, `POST /devices/{id}/downlink`, `POST /devices/{id}/claim`,
  `GET /devices/{id}/commands`, `GET /commands`, `GET /groups`,
  `GET /telemetry`, `GET /telemetry/last`, `GET /telemetry/status`,
  `GET /insights/summary`, `GET /insights/timeseries`.
- **ingestion** (`services/ingestion/app/routes/ingest.py`):
  `POST /ingest/http/{device_id}`, `GET /health`.
- Auth header requirements, error format (see `ApiExceptionListener.php`),
  payload examples (JSON).
- Mermaid: `sequenceDiagram` for a representative authenticated request.

Sources: controller + route files above, `services/device-mgmt/src/Entity/*`.

### 6. `iiot-platform-auth.md` — authentication

- Ed25519 JWT signing/verification, JWKS endpoint, why stateless.
- Login → access token + refresh token; refresh rotation with `family_id`;
  revocation on logout. Token TTLs.
- API keys for devices (`api_key_hash`), MQTT credential provisioning
  (`BrokerCredentialProvisioner`).
- Mercure tokens for the dashboard SSE feed.
- Mermaid: `sequenceDiagram` for login/refresh/rotate; `flowchart` for who
  verifies what.

Sources: `services/auth/src/*`, `services/device-mgmt/src/Security/*`,
`services/device-mgmt/src/Service/BrokerCredentialProvisioner.php`.

### 7. `iiot-platform-data-flow.md` — end-to-end flows

The heart of the docs. Detailed narrative + sequence diagrams for:

- **Uplink, MQTT device**: device publishes → Mosquitto → ingestion consumer →
  normaliser → TimescaleDB → Mercure event → dashboard update.
- **Uplink, HTTP**: `POST /ingest/http/{device_id}` → ingestion.
- **Uplink, Modbus TCP**: ingestion polls registers (`modbus_register_config`).
- **Uplink, LoRaWAN**: gateway → UDP 1700 → gateway-bridge → ChirpStack → MQTT
  app events → ingestion.
- **Downlink**: dashboard → device-mgmt → Mosquitto (or ChirpStack queue) →
  device responds → ack consumer updates `commands` status → Mercure.
- Mermaid: one `sequenceDiagram` per flow (5 total).

Sources: `services/ingestion/app/*.py`, `services/device-mgmt/src/Consumer/*`,
`services/device-mgmt/src/Service/DownlinkService.php`.

### 8. `iiot-platform-telemetry.md` — telemetry pipeline

- The normaliser (`decoder.py`, `normalizer.py`): raw payload → rows.
- `telemetry_points` hypertable and `telemetry_raw` audit; write path (batch
  `COPY`), indexes, no PK rationale.
- Continuous aggregates (`telemetry_1m/_1h/_1d`) and refresh policies.
- Retention (30-day rollup window).
- The insights/timeseries API queries.
- Mermaid: `flowchart LR` of the pipeline; reference ERD for schema.

Sources: `db/init/02-telemetry.sql`, `services/ingestion/app/writer.py`,
`services/device-mgmt/src/Service/InsightsReader.php`.

### 9. `iiot-platform-commands.md` — command lifecycle

- `commands` table fields; status enum `sent → acked | failed`.
- Downlink paths for MQTT vs LoRaWAN; `queue_item_id` (ChirpStack queue).
- Ack handling consumers (`ConsumeMqttAckCommand`,
  `ConsumeDownlinkEventsCommand`).
- Mermaid: `stateDiagram-v2` for the command status machine + one sequence
  for a downlink.

Sources: `services/device-mgmt/src/Entity/Command*.php`,
`services/device-mgmt/src/Consumer/*`, `services/device-mgmt/src/Service/CommandService.php`.

### 10. `iiot-platform-lorawan.md` — LoRaWAN integration

- Components: gateway-bridge, ChirpStack NS/AS, ChirpStack REST API facade.
- Paths: gateway UDP 1700 → MQTT → NS → AS events → ingestion;
  downlink via REST API queue items.
- ChirpStack admin login (`admin`/`admin` bootstrap), `set-password` CLI.
- `chirpstack.localhost` Caddy route, gRPC-web note.
- Mermaid: `flowchart LR` of the LoRaWAN path.

Sources: `deploy/chirpstack/*`, `deploy/caddy/Caddyfile`,
`services/device-mgmt/src/Service/DownlinkService.php`.

### 11. `iiot-platform-mqtt.md` — MQTT integration

- Mosquitto layout: config, ACL file, auth, internal-only network.
- Topic conventions (uplink, downlink, ack/lifecycle) — derive from
  `services/ingestion/app/mqtt.py` and consumer commands.
- Credential provisioning when a device is registered/claimed.
- Mermaid: `flowchart LR` of broker topology + topic list table.

Sources: `deploy/mqtt/*`, `services/ingestion/app/mqtt.py`,
`services/device-mgmt/src/Consumer/*`, `BrokerCredentialProvisioner.php`.

### 12. `iiot-platform-deployment.md` — deployment

- The compose stack: all services, images, networks, published ports.
- Environment variables table (from `deploy/.env.example`), which are secrets.
- Caddy routes + `CHIRPSTACK_SITE_ADDR` / hosts entry note.
- Backup (`deploy/backup`) and runbook pointer.
- Mermaid: `flowchart LR` of the host → containers → networks topology.

Sources: `deploy/docker-compose.yml`, `deploy/.env.example`,
`deploy/caddy/Caddyfile`, `deploy/runbook.md`.

### 13. `iiot-platform-hosting.md` — hosting and server requirements

Where the platform can run, with concrete minimum requirements:

- **Cloud VPS**: minimum CPU/RAM/disk, OS (Ubuntu 22.04+), Docker Engine ≥ 24
  + Compose v2, required open ports (80, 443, 1700/udp, SSH), and the runbook
  firewall example. Reference `deploy/runbook.md`.
- **Industrial RPi / edge device**: which models are suitable (RPi 4/5 with
  sufficient RAM), 64-bit OS, Docker install, local-first operation,
  SD-card/eMMC + power considerations, and the production note that DB/broker
  ports must not be published.
- **Exposing the edge Pi to the internet (only if required)** — a decision
  section comparing, with steps, caveats and when to use each:
  - **Tailscale** (recommended default: private, encrypted, no open ports,
    works behind NAT, good for operator access);
  - **Cloudflare Tunnel** (no open ports, public HTTPS hostname, needs a
    domain on Cloudflare, hides the origin IP);
  - **Port forwarding** (classic router NAT, only when the above are not an
    option; risks and mitigations — fixed IP/DDNS, keep SSH minimal,
    never expose 1883/5432).
- Mermaid: `flowchart LR` deciding exposure method (needs public access? →
  Tailscale / Cloudflare / port forwarding).

### 14. `iiot-platform-security.md` — security model

- Network isolation: `backend` network `internal: true`, published ports only
  where needed, DB port firewalled in production.
- Secrets management (`.env`, no secrets in images).
- JWT/Ed25519, API keys, MQTT password auth, ChirpStack DB ownership fix.
- Input validation, MQTT ACLs.
- Mermaid: `flowchart` of trust boundaries (host / frontend / backend / db).

Sources: `deploy/docker-compose.yml`, `services/*/Dockerfile`,
`deploy/mqtt/acl`, `deploy/chirpstack/chirpstack.toml`.

### 15. `iiot-platform-testing.md` — testing

- E2E suite (`tests/e2e/`): what it covers (18 checks), how to run
  (`docker compose -p iiot-platform-e2e -f deploy/docker-compose.yml -f
  deploy/docker-compose.test.yml up --exit-code-from e2e`), CI workflow.
- Unit/integration test locations per service.
- Mermaid: `flowchart LR` of the CI pipeline.

Sources: `.github/workflows/e2e.yml`, `tests/e2e/run_e2e.py`,
`deploy/docker-compose.test.yml`.

### 16. `iiot-platform-glossary.md` — glossary

Short alphabetical definitions: uplink, downlink, ack, LoRaWAN/NS/AS,
gateway-bridge, MQTT topic, TimescaleDB hypertable, continuous aggregate,
Mercure/SSE, JWT/JWKS, Ed25519, claim, queue item, FPort, dev EUI.

## Suggested writing order

1. `specs/README.md` (index) — write last once everything is linked.
2. `iiot-platform-overview.md` → `iiot-platform-architecture.md`
   (foundation, unblocks everything).
3. `iiot-platform-data-flow.md` (the five sequence diagrams — the hardest,
   most valuable).
4. `iiot-platform-api.md`, `iiot-platform-auth.md`.
5. `iiot-platform-telemetry.md`, `iiot-platform-commands.md`,
   `iiot-platform-lorawan.md`, `iiot-platform-mqtt.md`.
6. `iiot-platform-deployment.md`, `iiot-platform-hosting.md`,
   `iiot-platform-security.md`, `iiot-platform-testing.md`.
7. `iiot-platform-glossary.md`.
8. `specs/README.md` index last, with cross-links verified.

## Acceptance

- Every document exists in `specs/`, follows the conventions above.
- Every Mermaid block renders (no `|`/`*` unquoted in labels).
- No factual duplication: shared facts live in one doc and are linked from
  others.
- Contents match the deployed stack on the Pi (`ssh rpi4`).
