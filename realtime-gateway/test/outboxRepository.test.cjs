'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { OutboxRepository } = require('../src/outboxRepository.cjs');

test('claims outbox rows with one PostgreSQL transaction and releases the client', async () => {
  const calls = [];
  let released = false;
  const client = {
    async query(text, values) {
      calls.push({ text, values });
      if (/RETURNING events\.id/.test(text)) return { rows: [{ id: 4, event_type: 'schedule.revision.changed' }] };
      return { rows: [] };
    },
    release() { released = true; }
  };
  const repository = new OutboxRepository({
    pool: { async connect() { return client; } }, channel: 'player_realtime_outbox'
  });
  const rows = await repository.claim(25);
  assert.equal(calls[0].text, 'BEGIN');
  assert.match(calls[1].text, /FOR UPDATE SKIP LOCKED/);
  assert.deepEqual(calls[1].values, [25]);
  assert.equal(calls[2].text, 'COMMIT');
  assert.equal(released, true);
  assert.equal(rows[0].id, 4);
});

test('rolls back and releases the transaction client when a claim fails', async () => {
  const calls = [];
  let released = false;
  const client = {
    async query(text) {
      calls.push(text);
      if (/WITH candidates/.test(text)) throw new Error('database unavailable');
      return { rows: [] };
    },
    release() { released = true; }
  };
  const repository = new OutboxRepository({
    pool: { async connect() { return client; } }, channel: 'player_realtime_outbox'
  });
  await assert.rejects(repository.claim(10), /database unavailable/);
  assert.equal(calls.at(-1), 'ROLLBACK');
  assert.equal(released, true);
});
