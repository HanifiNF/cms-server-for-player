# Realtime Gateway

Node.js Socket.IO gateway for the WirGroup CMS. This is the first realtime
implementation stage: it authenticates paired Players, creates one room per
Studio, consumes the PostgreSQL `outbox_events` table, and publishes revision
hints. The Electron Player remains on REST heartbeat synchronization until the
separate Player integration stage is enabled.

## Reliability model

The CMS transaction writes business data, increments the target Studio
revision, and inserts an outbox row. PostgreSQL `NOTIFY` wakes this gateway only
as a low-latency hint. The gateway also polls pending rows, so a notification or
gateway restart cannot lose the update. Outbox delivery is at-least-once;
revision numbers make duplicate events harmless.

The gateway never transports film bytes, LDG keys, schedules, or asset
manifests. A future Player handler will receive `sync:hint` and retrieve the
authoritative snapshot from the existing authenticated REST API.

## Requirements

- Node.js 22.12 or newer
- PostgreSQL used by `cms-server`
- A dedicated PostgreSQL login for this gateway

Example least-privilege database role, executed by a PostgreSQL administrator:

```sql
CREATE ROLE wir_realtime LOGIN PASSWORD 'replace-with-a-long-random-password';
GRANT CONNECT ON DATABASE wir_player_cms TO wir_realtime;
GRANT USAGE ON SCHEMA public TO wir_realtime;
GRANT SELECT ON TABLE public.devices TO wir_realtime;
GRANT SELECT, UPDATE ON TABLE public.outbox_events TO wir_realtime;
```

The role does not need access to users, passwords, film files, encryption keys,
or storage credentials.

## Local setup

```powershell
cd realtime-gateway
Copy-Item .env.example .env
pnpm install
pnpm test
pnpm start
```

`npm install`, `npm test`, and `npm start` are equivalent when npm is available.
Edit `.env` with the dedicated PostgreSQL account before starting the service.
Do not copy the entire CMS `.env`, because the gateway does not need the CMS
encryption or administrator secrets.

Then enable the low-latency PostgreSQL notification in the CMS `.env`:

```ini
realtime.enabled = true
realtime.notificationChannel = player_realtime_outbox
```

Both services must use the same notification channel. Polling remains active
even if `realtime.enabled` is false.

## Endpoints

- `GET /live` confirms the Node.js process is running.
- `GET /health` checks PostgreSQL, the worker, connected Player count, and
  non-sensitive outbox statistics.
- Socket.IO namespace: `/player`
- Default Socket.IO path: `/socket.io`

Example health check:

```powershell
Invoke-RestMethod http://127.0.0.1:3001/health
```

## Socket authentication

The `/player` namespace requires `auth.token`, which is the device token created
during pairing. The gateway hashes it with SHA-256 and matches only an active
device. The raw token is never persisted or logged. If `auth.deviceId` is
provided, it must match the token's Studio.

Only one live socket is retained per Studio. A newer connection emits
`session:replaced` to the previous connection and replaces it.

## Events emitted by the gateway

### `sync:initial`

Sent immediately after authentication with the current asset and schedule
revisions.

### `sync:hint`

Sent when a non-revocation outbox event targets the connected Studio:

```json
{
  "schema": "player-realtime.v1",
  "eventId": 120,
  "deviceId": "studio-public-id",
  "inventoryRevision": 4,
  "assetRevision": 8,
  "scheduleRevision": 12,
  "reason": "schedule.revision.changed",
  "occurredAt": "2026-08-24T10:20:00Z"
}
```

### `device:revoked`

Sent for `studio.revoked`, `studio.pairing.reset`, and `studio.deleted`, after
which the gateway disconnects that Player socket.

The gateway accepts the optional `sync:applied` event for operational logging.
It does not trust the acknowledgement as the source of truth.

## Failure behavior

- Offline Player: the event is marked processed; `sync:initial` supplies the
  latest revisions when the Player reconnects.
- PostgreSQL listener failure: polling continues and the listener reconnects.
- Gateway interruption during processing: stale `processing` rows return to
  `pending` on startup.
- Publish error: bounded exponential retry is applied; rows become `failed`
  after `OUTBOX_MAX_ATTEMPTS`.
- Invalid or revoked token: the Socket.IO handshake is rejected.

## Current stage boundary

Do not enable `SOCKET_ENABLED` in the Electron Player yet. Player event handling,
REST snapshot triggering, realtime status UI, and WSS deployment belong to the
next implementation stages.
