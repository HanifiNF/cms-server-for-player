'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { once } = require('node:events');
const { io: createClient } = require('socket.io-client');
const { createGateway, toRealtimeMessage } = require('../src/gateway.cjs');

const silentLogger = { debug() {}, info() {}, warn() {}, error() {} };

async function fixture() {
  const config = {
    host: '127.0.0.1', port: 0, socketPath: '/socket.io', corsOrigins: ['http://localhost:8080']
  };
  const pool = { async query() { return { rows: [{ '?column?': 1 }] }; } };
  const authenticate = (socket, next) => {
    if (socket.handshake.auth.token !== 'valid-token') {
      const error = new Error('invalid token'); error.data = { code: 'invalid_player_token' }; return next(error);
    }
    socket.data.device = {
      id: 1, publicId: 'studio-1', name: 'Studio 1', locationId: 2,
      assetRevision: 4, scheduleRevision: 7
    };
    next();
  };
  const gateway = createGateway({ config, pool, authenticate, logger: silentLogger });
  const address = await gateway.start();
  return { gateway, url: `http://127.0.0.1:${address.port}` };
}

function client(url, token = 'valid-token') {
  return createClient(`${url}/player`, {
    path: '/socket.io', transports: ['websocket'], forceNew: true,
    reconnection: false, auth: { token, deviceId: 'studio-1' }
  });
}

test('sends initial revisions after an authenticated Player connects', async t => {
  const { gateway, url } = await fixture();
  const socket = client(url);
  t.after(async () => { socket.disconnect(); await gateway.stop(); });
  const [message] = await once(socket, 'sync:initial');
  assert.equal(message.deviceId, 'studio-1');
  assert.equal(message.assetRevision, 4);
  assert.equal(message.scheduleRevision, 7);
  assert.equal(gateway.registry.size, 1);
});

test('rejects unauthenticated socket connections', async t => {
  const { gateway, url } = await fixture();
  const socket = client(url, 'invalid-token');
  t.after(async () => { socket.disconnect(); await gateway.stop(); });
  const [error] = await once(socket, 'connect_error');
  assert.equal(error.data.code, 'invalid_player_token');
});

test('publishes revision hints only to the targeted device room', async t => {
  const { gateway, url } = await fixture();
  const socket = client(url);
  t.after(async () => { socket.disconnect(); await gateway.stop(); });
  await once(socket, 'sync:initial');
  const hintPromise = once(socket, 'sync:hint');
  const result = await gateway.publisher.publish({
    id: 22, event_type: 'schedule.revision.changed', created_at: '2026-08-24T00:00:00Z',
    payload: { device_id: 'studio-1', asset_revision: 4, schedule_revision: 8 }
  });
  const [message] = await hintPromise;
  assert.equal(result.connected, true);
  assert.equal(message.eventId, 22);
  assert.equal(message.scheduleRevision, 8);
});

test('reports database and worker readiness without exposing configuration secrets', async t => {
  const { gateway, url } = await fixture();
  t.after(async () => gateway.stop());
  gateway.setWorkerRunning(true);
  const response = await fetch(`${url}/health`);
  const body = await response.json();
  assert.equal(response.status, 200);
  assert.equal(body.status, 'ready');
  assert.equal(body.database, 'available');
  assert.equal(Object.hasOwn(body, 'databaseUrl'), false);
});

test('notifies and disconnects a Player when its Studio is revoked', async t => {
  const { gateway, url } = await fixture();
  const socket = client(url);
  t.after(async () => { socket.disconnect(); await gateway.stop(); });
  await once(socket, 'sync:initial');
  const revokedPromise = once(socket, 'device:revoked');
  const disconnectedPromise = once(socket, 'disconnect');
  await gateway.publisher.publish({
    id: 23, event_type: 'studio.revoked', created_at: '2026-08-24T00:00:00Z',
    payload: { device_id: 'studio-1', asset_revision: 4, schedule_revision: 8 }
  });
  const [message] = await revokedPromise;
  await disconnectedPromise;
  assert.equal(message.reason, 'studio.revoked');
  assert.equal(gateway.registry.size, 0);
});

test('normalizes JSON outbox payloads into the stable realtime contract', () => {
  const message = toRealtimeMessage({
    id: '10', event_type: 'asset.revision.changed',
    payload: JSON.stringify({ device_id: 'studio-9', asset_revision: 3, schedule_revision: 2 })
  });
  assert.deepEqual(
    { eventId: message.eventId, deviceId: message.deviceId, assetRevision: message.assetRevision, scheduleRevision: message.scheduleRevision },
    { eventId: 10, deviceId: 'studio-9', assetRevision: 3, scheduleRevision: 2 }
  );
});
