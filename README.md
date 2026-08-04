# WirGroup Player CMS

CodeIgniter 4 application for managing media players, per-device media
inventories, playlists, schedules, delivery acknowledgments, and audit logs.

This directory contains the CMS and HTTP API. The Socket.IO realtime gateway
will live in a separate sibling directory so PHP remains the source of truth
for users, devices, assets, and schedules.

## Requirements

- PHP 8.2 or newer (PHP 8.5 is used by the local workstation)
- Composer
- PostgreSQL
- PHP extensions: `intl`, `mbstring`, `pgsql`, and `pdo_pgsql`
- PHP test extensions: `sqlite3` and `pdo_sqlite`

The local workstation currently uses PHP 8.5, Composer 2.10, and PostgreSQL 18.
Both `pgsql` and `pdo_pgsql` are enabled.

## Local setup

From `cms-server`:

```powershell
Copy-Item env .env
```

Edit `.env` and set the local PostgreSQL credentials:

```dotenv
database.default.hostname = localhost
database.default.database = wir_player_cms
database.default.username = wir_player_app
database.default.password = your_password
database.default.DBDriver = Postgre
database.default.port = 5432
database.default.schema = public
cms.adminApiKey = a_long_random_admin_secret
cms.enrollmentPepper = a_different_random_secret_with_at_least_32_characters
```

Create the database with PostgreSQL tooling, then run:

```powershell
php spark migrate
php spark serve
```

The development server listens at `http://localhost:8080` by default.

## Health endpoint

```text
GET /api/health
```

Example response:

```json
{
  "status": "ok",
  "service": "wir-player-cms",
  "environment": "development",
  "timestamp": "2026-08-04T03:00:00+00:00"
}
```

This is a liveness endpoint and intentionally does not query PostgreSQL.
A readiness endpoint that checks the database and realtime gateway will be
added when those services are connected.

## Operator authentication and device claim

Create the first CMS accounts from the command line. A generated password is
shown once and is never stored in plaintext:

```powershell
php spark user:create admin@example.com "CMS Administrator" admin
php spark user:create operator@example.com "Lobby Operator" operator
```

Operator sessions are short-lived, revocable, rate-limited at login, and use
opaque Bearer tokens whose SHA-256 digests are stored in PostgreSQL.

```text
POST /api/auth/login
GET  /api/auth/me
POST /api/auth/logout
```

An administrator creates a pending device with `POST /api/operator/devices`.
An authenticated operator lists permitted devices and claims one:

```text
GET  /api/operator/devices/available
POST /api/player/claim
```

The claim binds the pending CMS device, operator, and stable Player install ID.
It returns a long-lived device token; the operator token is not retained by the
Player.

## Pairing-code fallback

Pairing-code endpoints require the `X-CMS-Admin-Key` header and are disabled by
default with `cms.enablePairingCode = false`. They remain only as an optional
technician fallback during development.

```text
POST /api/admin/devices/enroll
GET  /api/admin/devices
GET  /api/admin/devices/{deviceId}
```

Create an enrollment code:

```json
{
  "name": "Lobby Player",
  "timezone": "Asia/Jakarta"
}
```

The returned enrollment code is valid for 15 minutes and can only be used
once. The player exchanges it for its permanent token:

```text
POST /api/player/register
```

```json
{
  "enrollment_code": "ABCD-2345",
  "device_fingerprint": "stable-id-generated-by-the-player",
  "app_version": "1.1.0",
  "platform": "win32-x64",
  "timezone": "Asia/Jakarta"
}
```

The registration response contains the Bearer token once. The player must keep
that token in protected local storage and send it on heartbeat requests:

```text
POST /api/player/heartbeat
Authorization: Bearer {playerToken}
```

Before clearing its local credentials, a player revokes its pairing with:

```text
POST /api/player/unregister
Authorization: Bearer {playerToken}
```

Only token digests and enrollment-code digests are stored in PostgreSQL.

## Database foundation

The first migration creates:

- `users`: CMS operators and roles
- `devices`: registered player PCs and synchronization revisions
- `assets`: CMS-managed media catalog
- `device_assets`: media inventory reported by each player
- `schedules`: schedule definitions and recurrence settings
- `schedule_targets`: target player PCs
- `schedule_items`: ordered playlist items
- `schedule_deliveries`: per-device delivery and acknowledgment status
- `outbox_events`: reliable handoff to the future Socket.IO gateway
- `audit_logs`: operator and system activity history

Application timestamps are written in UTC. Each schedule also stores the
timezone used by the operator, with `Asia/Jakarta` as the default display zone.

## Tests

```powershell
composer test
```

The test suite uses an isolated in-memory SQLite database. It verifies health,
operator login/logout, roles, device assignment and claim, separation of
operator/device tokens, optional one-time pairing, heartbeat, revoke, and
device listing.
