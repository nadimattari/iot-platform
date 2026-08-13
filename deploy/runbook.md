# Deployment Runbook (Task 23)

Provisions, operates, backs up, restores, and upgrades the self-hosted IIoT
platform on a fresh VPS. All commands assume a working directory of the repo
clone (usually `iot-platform/`) and Docker Engine with the Compose plugin.

## 1. Prerequisites

- Ubuntu 22.04+ (or any distro with a recent Docker Engine + Compose plugin)
- Docker Engine ≥ 24 and `docker compose` (v2) plugin
- A domain (or subdomain) whose DNS you control, e.g. `iot.example.com`
- UDP 1700 reachable from your LoRaWAN gateway's LAN (packet-forwarders use UDP)

## 2. DNS

Create A records pointing at the VPS public IP:

| Name                  | Type | Value      |
|-----------------------|------|------------|
| `iot.example.com`     | A    | `<vps-ip>` |
| `chirpstack.<domain>` | A    | `<vps-ip>` |

If you need a separate subdomain for ChirpStack (its UI cannot be hosted on a
path), create one for `CHIRPSTACK_SITE_ADDR` below. Both names are served by
the same Caddy container; one IP is enough.

## 3. Firewall / port requirements

Open only what the platform needs:

| Port      | Protocol | Purpose                                                | Who needs it                |
|-----------|----------|--------------------------------------------------------|-----------------------------|
| 80        | TCP      | HTTP → auto-redirect to HTTPS (Let's Encrypt)          | public                      |
| 443       | TCP      | HTTPS: dashboard, APIs, Mercure SSE                    | public                      |
| 1700      | UDP      | Semtech UDP packet-forwarder ↔ gateway bridge          | LoRaWAN gateways on the LAN |
| 22        | TCP      | SSH (administrator only, restrict to your IP)          | admin                       |

Optional (recommended to firewall to your IP, not public):

| Port | Protocol | Purpose                                      |
|------|----------|----------------------------------------------|
| 8080 | TCP      | ChirpStack Web UI / API                      |
| 8090 | TCP      | ChirpStack REST API facade (platform use)    |

Everything else (Mosquitto 1883, PostgreSQL 5432, Redis, Mercure) is internal:
the compose file puts them on an internal-only network (`backend`), and the db
and broker are *not* exposed to the host. Do not open them in the firewall.

Example with UFW:

```bash
sudo ufw allow 22/tcp          # restrict to your IP if possible
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 1700/udp
sudo ufw enable
```

## 4. First-time provisioning

```bash
git clone git@github.com:nadimattari/iot-platform.git
cd iot-platform

# Real secrets, never commit them. deploy/.env is git-ignored.
cp deploy/.env.example deploy/.env
$EDITOR deploy/.env
```

Key values in `deploy/.env`:

| Variable                 | Value for production                                   |
|--------------------------|--------------------------------------------------------|
| `DOMAIN`                 | `iot.example.com` (triggers Caddy auto-TLS)            |
| `CHIRPSTACK_SITE_ADDR`   | `https://chirpstack.iot.example.com`                   |
| `POSTGRES_PASSWORD`      | long random string                                     |
| `MOSQUITTO_PASSWORD`     | long random string                                     |
| `APP_SECRET`             | long random string                                     |
| `MERCURE_PUBLISHER_JWT_KEY` | long random string                                  |
| `MERCURE_SUBSCRIBER_JWT_KEY` | long random string                                 |
| `AUTH_ADMIN_EMAIL` / `AUTH_ADMIN_PASSWORD` | first admin account (seeded on boot) |
| `CHIRPSTACK_DB_PASSWORD` | long random string                                     |
| `CHIRPSTACK_MQTT_PASSWORD` | long random string                                    |
| `CHIRPSTACK_API_SECRET`   | long random string                                     |
| `CHIRPSTACK_APPLICATION_ID` | keep the generated UUID or regenerate before first ChirpStack app creation |

Generate secrets with `openssl rand -hex 32` (or `-base64 48`).

Boot the stack:

```bash
cd deploy
docker compose up -d --build
docker compose ps                # all services should be running + healthy
```

Caddy provisions TLS automatically via Let's Encrypt the first time `DOMAIN`
is a real hostname (validates over port 80, so 80 must be open).

First-login checklist:

- `https://iot.example.com` → dashboard login (uses `AUTH_ADMIN_*` creds)
- `https://chirpstack.iot.example.com` → ChirpStack UI (uses
  `CHIRPSTACK_ADMIN_EMAIL`/`CHIRPSTACK_ADMIN_PASSWORD`)
- Register a device in the dashboard, then publish MQTT to confirm ingestion

## 5. Backups

The DB holds all platform state (devices, users, commands, telemetry in
TimescaleDB hypertables + continuous aggregates) and the ChirpStack state.
Backup script dumps both databases as plain SQL (hypertables/aggregates
round-trip cleanly):

```bash
deploy/backup/backup.sh                      # → deploy/backup/backups/
BACKUP_DIR=/srv/backups deploy/backup/backup.sh   # custom location
```

Schedule with cron (as a user with docker access):

```cron
# /etc/cron.d/iiot-backup  (or `crontab -e`)
0 2 * * * BACKUP_DIR=/srv/backups RETENTION_DAYS=14 /opt/iot-platform/deploy/backup/backup.sh >> /var/log/iiot-backup.log 2>&1
```

`RETENTION_DAYS` (default 7) prunes old dumps. Push `/srv/backups` to an
offsite target (rclone, restic, or `scp` to another host) — the point of a
backup is a copy on a different machine.

### Restore

```bash
deploy/backup/restore.sh                      # latest pair from backups dir
deploy/backup/restore.sh /path/iiot-....sql.gz /path/chirpstack-....sql.gz
```

Restore stops the writers (ingestion, consumers, ChirpStack), clears both
databases, loads the dumps, and restarts the writers.

### Restore onto a fresh stack

On a clean VPS (fresh DB volume), the `db/init` bootstrap creates the
databases and extensions. Then:

```bash
docker compose up -d db   # wait until healthy (creates empty DBs)
deploy/backup/restore.sh  # load the dumps
docker compose up -d
```

## 6. Upgrades

```bash
cd iot-platform
git pull --ff-only

# Config-only change: just restart.
docker compose -f deploy/docker-compose.yml up -d

# Image/Dockerfile change: rebuild.
docker compose -f deploy/docker-compose.yml up -d --build

# Schema migration (only when this repo adds one):
docker compose exec device-mgmt bin/console doctrine:migrations:migrate --no-interaction
```

Pre-upgrade: run `deploy/backup/backup.sh`. Post-upgrade: `docker compose ps`
until all healthchecks are green, then smoke-test the dashboard.

Rollback: `git checkout <previous-tag>` then repeat `up -d --build`.

## 7. Healthchecks & recovery

Every service declares a `healthcheck`; Compose restarts containers that exit
(`restart: unless-stopped`) and the db/mqtt/chirpstack dependencies start only
after their dependencies are healthy.

- `docker compose ps` — the `STATUS` column shows `healthy`/`unhealthy`
- `docker compose events` — stream container state changes
- Containers exit with a non-zero status are restarted automatically by
  `restart: unless-stopped` (Docker backs off and retries indefinitely).
  Note: `docker compose kill`/`docker kill` are treated by Docker as
  intentional stops and do *not* trigger the restart policy — simulate a real
  crash with a failing process instead
- Container logs are size-capped (`max-size: 20m`, `max-file: 3`) so a chatty
  container cannot fill the disk. Check disk with `df -h`.

## 8. Observability

- Health endpoints: `https://iot.example.com/api/v1/health` (device-mgmt),
  `/healthz` (Caddy), `/ingest/health` (ingestion)
- Logs: `docker compose logs -f <service>`
- Mercure hub: `docker compose logs -f caddy`
- TimescaleDB: `docker compose exec db psql -U iiot -d iiot`

## 9. Known constraints (local dev)

- `POSTGRES_DB` defaults to `postgres`; the real databases are `iiot` and
  `chirpstack`, created by `db/init` on first boot.
- The db and broker ports are only bound on the host in the dev compose for
  convenience; on a production VPS, remove the `ports:` entries for `db`,
  `mqtt`, `chirpstack`, and `chirpstack-rest-api` (or firewall them) so only
  Caddy is reachable from outside.
