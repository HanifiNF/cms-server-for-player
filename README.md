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

## Player asset inventory

An active Player synchronizes its Media Folder and managed downloads through:

```text
POST /api/player/assets/sync
Authorization: Bearer <device-token>
```

The endpoint accepts an authoritative snapshot of at most 2,000 items. It
upserts records by `(device_id, media_key)`, marks omitted records as
`missing`, and increments `devices.inventory_revision` in one transaction.
Absolute paths and unsafe relative paths are rejected. In the admin control
panel, open **Players → View Assets** to search and filter a Player inventory.

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

### Run through Laragon Apache (recommended on Windows)

The PHP development server handles only one long-running media response at a
time on Windows. For concurrent downloads, login, heartbeat, and CMS requests,
install `deploy/apache/wir-player-cms.conf` into Laragon's
`C:\laragon\etc\apache2\sites-enabled` directory and restart Apache. The
provided development virtual host serves `public/` at:

```text
http://localhost:8080
```

Do not run `php spark serve` on port 8080 at the same time. For another device
on the same LAN, use the CMS computer's LAN IP instead of `localhost` and allow
TCP port 8080 through Windows Firewall.

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

Open `http://localhost:8080`. When no administrator exists, the CMS redirects
to `/setup` to create the first administrator. That one-time route becomes
unavailable as soon as an administrator record exists.

After signing in, use the control panel to:

- create, edit, activate, and deactivate operator accounts;
- reset passwords and revoke the account's active API sessions;
- create pending Player records and assign them to an operator;
- monitor pending, online, and offline Player status.

The command below remains available as a recovery or automation fallback. Its
generated password is shown once and is never stored in plaintext:

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

Pairing revocation is an administrator-only CMS action. The Player cannot
revoke itself. Once revoked, its next heartbeat is rejected and it returns to
the pairing screen. Revoked Player records can then be permanently deleted
from the CMS control panel.

Only token digests and enrollment-code digests are stored in PostgreSQL.

## Remote media distribution

Administrators manage films from **Control Center → Assets**:

1. upload a media file to the private CMS storage;
2. assign it to one or more active Players;
3. refresh the Player, or wait for its first heartbeat after startup;
4. monitor the assignment status changing from `missing` to `ready`.

Player-only endpoints use the long-lived device Bearer token:

```text
GET  /api/player/assets/assigned
GET  /api/player/assets/{assetPublicId}/download
POST /api/player/assets/sync
```

The manifest exposes relative download URLs so Players can use the LAN or
public CMS hostname they were paired with. Downloads are allowed only for a
Player that has the specific asset assignment. Uploaded files are stored below
`writable/uploads/assets`, outside the public web root. Set PHP
`upload_max_filesize` and `post_max_size` above the largest film size before
uploading production media.

Authenticated media downloads support single HTTP byte ranges over HTTP or
HTTPS. The endpoint returns `Accept-Ranges: bytes`, strong SHA-256 `ETag`
validators, `206 Partial Content` with `Content-Range`, and `416` for invalid
ranges. Files are streamed in 1 MB chunks instead of being loaded into PHP
memory in full. This allows a Player to continue a saved `.part` download after
a restart or power interruption.

Film duration is detected automatically during upload with `ffprobe`; operators
do not enter it manually. Configure `media.ffprobePath` in `.env` when ffprobe
is not available on the CMS process PATH. If server-side probing is unavailable,
the asset remains marked **Detecting…** and the first assigned Player that
downloads and verifies the file reports the duration back to the catalog.

The Assets upload form submits with upload progress feedback: percentage,
transferred bytes, current throughput, and estimated time remaining. After the
network transfer reaches 100%, the UI switches to **Processing media…** while
the CMS moves the file, hashes it, probes duration, and commits its database
record. Operators can cancel only during the network-transfer phase. This is a
single-request upload; interrupted uploads restart from zero until resumable
chunk uploads are implemented.

Asset lifecycle actions are intentionally separate:

- **Unassign** removes the CMS assignment but retains the Player's verified
  local cache.
- **Unassign & Remove** records a pending removal. On its next startup or
  manual refresh, the Player deletes the managed file only when VLC is not
  using it, then acknowledges completion to the CMS.
- **Delete Asset** permanently deletes the private CMS upload and its database
  record. It is blocked while any Player assignment, pending removal, or
  schedule reference exists.

Because the Socket.IO gateway is not enabled yet, offline or running Players
receive removal requests through the regular startup/manual-refresh flow.

## Schedule management

Administrators create one-time playlists from **Control Center → Schedules**.
After selecting a target Player, the media picker contains only inventory items
that the Player most recently reported as `ready`. Playlist order and each
item's detected duration can be adjusted. The CMS interprets the chosen wall
clock time in the Player timezone, stores UTC timestamps, rejects overlaps on
the same Player, and increments `devices.schedule_revision` after every create,
update, enable/disable, or delete operation.

Until Socket.IO is enabled, the Player retrieves the authoritative snapshot on
startup and manual refresh:

```text
GET /api/player/schedules
Authorization: Bearer <device-token>
```

The token scopes the response to one Player. Local Media Folder entries are
identified by `mediaKey`; CMS-managed downloads also include their catalog
`assetId`. Absolute Player paths never enter the CMS database or API. The
Player resolves those identities locally, atomically caches the accepted
revision, and its scheduler starts VLC when the configured time arrives. The
last accepted cache remains usable while the CMS is temporarily offline.

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
