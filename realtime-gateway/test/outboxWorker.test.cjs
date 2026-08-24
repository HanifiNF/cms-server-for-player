'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { OutboxWorker, retryDelay } = require('../src/outboxWorker.cjs');

const silentLogger = { debug() {}, info() {}, warn() {}, error() {} };

test('uses bounded exponential retry delays', () => {
  assert.equal(retryDelay(1, 1000, 60000), 1000);
  assert.equal(retryDelay(4, 1000, 60000), 8000);
  assert.equal(retryDelay(20, 1000, 60000), 60000);
});

test('processes claimed outbox events and marks them complete', async () => {
  const completed = [];
  let claims = 0;
  const repository = {
    async claim() { claims += 1; return claims === 1 ? [{ id: 1, event_type: 'schedule.revision.changed', attempts: 1 }] : []; },
    async complete(id) { completed.push(id); },
    async fail() { throw new Error('must not fail'); }
  };
  const published = [];
  const worker = new OutboxWorker({
    repository, publisher: { async publish(event) { published.push(event.id); return { deviceId: 'studio-1', connected: true }; } },
    logger: silentLogger, batchSize: 10
  });
  worker.running = true;
  await worker.wake();
  worker.running = false;
  assert.deepEqual(published, [1]);
  assert.deepEqual(completed, [1]);
  assert.equal(worker.stats.processed, 1);
});

test('returns failed events to pending with an exponential delay', async () => {
  const failures = [];
  const repository = {
    async fail(...args) { failures.push(args); }
  };
  const worker = new OutboxWorker({
    repository, publisher: { async publish() { throw new Error('temporary publish error'); } },
    logger: silentLogger, maxAttempts: 3, retryBaseMs: 500, retryMaxMs: 10000
  });
  await worker.process({ id: 8, attempts: 2, event_type: 'asset.revision.changed' });
  assert.equal(failures[0][0], 8);
  assert.equal(failures[0][2], 1000);
  assert.equal(failures[0][3], false);
  assert.equal(worker.stats.retried, 1);
});

test('moves events to failed after the configured attempt limit', async () => {
  const failures = [];
  const worker = new OutboxWorker({
    repository: { async fail(...args) { failures.push(args); } },
    publisher: { async publish() { throw new Error('permanent failure'); } },
    logger: silentLogger, maxAttempts: 3
  });
  await worker.process({ id: 9, attempts: 3, event_type: 'studio.updated' });
  assert.equal(failures[0][3], true);
  assert.equal(worker.stats.failed, 1);
});
