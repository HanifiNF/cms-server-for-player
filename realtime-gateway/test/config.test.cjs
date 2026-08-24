'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { loadConfig } = require('../src/config.cjs');

test('loads bounded gateway configuration without exposing CMS application secrets', () => {
  const config = loadConfig({
    NODE_ENV: 'test', HOST: '127.0.0.1', PORT: '0', LOG_LEVEL: 'debug',
    PGHOST: 'db.internal', PGPORT: '5433', PGDATABASE: 'cms', PGUSER: 'gateway', PGPASSWORD: 'secret',
    CORS_ORIGINS: 'http://localhost:8080, https://cms.example.com',
    OUTBOX_POLL_MS: '10', OUTBOX_BATCH_SIZE: '9999'
  });
  assert.equal(config.port, 0);
  assert.equal(config.database.host, 'db.internal');
  assert.equal(config.database.port, 5433);
  assert.deepEqual(config.corsOrigins, ['http://localhost:8080', 'https://cms.example.com']);
  assert.equal(config.outboxPollMs, 100);
  assert.equal(config.outboxBatchSize, 500);
});

test('rejects unsafe PostgreSQL notification channel names', () => {
  assert.throws(() => loadConfig({ OUTBOX_CHANNEL: 'outbox; DROP TABLE devices' }), /OUTBOX_CHANNEL/);
});
