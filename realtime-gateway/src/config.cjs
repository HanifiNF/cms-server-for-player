'use strict';

const path = require('path');
const dotenv = require('dotenv');

dotenv.config({ path: process.env.GATEWAY_ENV_FILE || path.resolve(__dirname, '..', '.env'), quiet: true });

function integer(value, fallback, minimum, maximum) {
  const parsed = Number.parseInt(String(value ?? ''), 10);
  if (!Number.isFinite(parsed)) return fallback;
  return Math.min(maximum, Math.max(minimum, parsed));
}

function list(value, fallback = []) {
  const values = String(value || '').split(',').map(item => item.trim()).filter(Boolean);
  return values.length ? values : fallback;
}

function socketPath(value) {
  const normalized = `/${String(value || '/socket.io').trim()}`.replace(/\/{2,}/g, '/').replace(/\/$/, '');
  return normalized || '/socket.io';
}

function channel(value) {
  const normalized = String(value || 'player_realtime_outbox').trim();
  if (!/^[a-z][a-z0-9_]{0,62}$/.test(normalized)) {
    throw new Error('OUTBOX_CHANNEL must be a valid lowercase PostgreSQL channel name.');
  }
  return normalized;
}

function loadConfig(environment = process.env) {
  const nodeEnvironment = String(environment.NODE_ENV || 'development').trim().toLowerCase();
  const sslMode = String(environment.PGSSLMODE || 'disable').trim().toLowerCase();
  const database = environment.DATABASE_URL
    ? { connectionString: environment.DATABASE_URL }
    : {
        host: environment.PGHOST || '127.0.0.1',
        port: integer(environment.PGPORT, 5432, 1, 65535),
        database: environment.PGDATABASE || 'wir_player_cms',
        user: environment.PGUSER || 'wir_realtime',
        password: environment.PGPASSWORD || ''
      };
  if (sslMode !== 'disable') {
    database.ssl = { rejectUnauthorized: sslMode !== 'no-verify' };
  }

  return Object.freeze({
    nodeEnvironment,
    host: String(environment.HOST || '127.0.0.1'),
    port: integer(environment.PORT, 3001, 0, 65535),
    logLevel: String(environment.LOG_LEVEL || 'info').toLowerCase(),
    socketPath: socketPath(environment.SOCKET_PATH),
    corsOrigins: list(environment.CORS_ORIGINS, ['http://localhost:8080']),
    database,
    outboxChannel: channel(environment.OUTBOX_CHANNEL),
    outboxPollMs: integer(environment.OUTBOX_POLL_MS, 1000, 100, 60000),
    outboxBatchSize: integer(environment.OUTBOX_BATCH_SIZE, 50, 1, 500),
    outboxMaxAttempts: integer(environment.OUTBOX_MAX_ATTEMPTS, 12, 1, 100),
    outboxStaleProcessingMs: integer(environment.OUTBOX_STALE_PROCESSING_MS, 60000, 5000, 3600000),
    outboxRetryBaseMs: integer(environment.OUTBOX_RETRY_BASE_MS, 1000, 100, 60000),
    outboxRetryMaxMs: integer(environment.OUTBOX_RETRY_MAX_MS, 60000, 1000, 3600000),
    shutdownTimeoutMs: integer(environment.SHUTDOWN_TIMEOUT_MS, 10000, 1000, 60000)
  });
}

module.exports = { loadConfig };
