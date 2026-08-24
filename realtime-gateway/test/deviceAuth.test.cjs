'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDeviceAuthenticator, tokenDigest } = require('../src/deviceAuth.cjs');

function authenticate(middleware, auth) {
  const socket = { handshake: { auth }, data: {} };
  return new Promise(resolve => middleware(socket, error => resolve({ socket, error })));
}

test('authenticates an active Player token and exposes only safe device metadata', async () => {
  const token = 'a'.repeat(43);
  let query;
  const pool = { async query(text, values) {
    query = { text, values };
    return { rows: [{ id: '7', public_id: 'studio-1', name: 'Studio 1', location_id: '2', asset_revision: '4', schedule_revision: '9' }] };
  } };
  const middleware = createDeviceAuthenticator({ pool });
  const result = await authenticate(middleware, { token, deviceId: 'studio-1' });
  assert.equal(result.error, undefined);
  assert.equal(query.values[0], tokenDigest(token));
  assert.equal(result.socket.data.device.publicId, 'studio-1');
  assert.equal(result.socket.data.device.assetRevision, 4);
  assert.equal(result.socket.data.device.scheduleRevision, 9);
  assert.equal(Object.hasOwn(result.socket.data.device, 'token'), false);
});

test('rejects a token whose claimed device id does not match', async () => {
  const pool = { async query() {
    return { rows: [{ id: '7', public_id: 'studio-1', name: 'Studio 1', location_id: null, asset_revision: 0, schedule_revision: 0 }] };
  } };
  const result = await authenticate(createDeviceAuthenticator({ pool }), {
    token: 'b'.repeat(43), deviceId: 'studio-2'
  });
  assert.equal(result.error.data.code, 'invalid_player_token');
});

test('does not query PostgreSQL for malformed tokens', async () => {
  let called = false;
  const pool = { async query() { called = true; return { rows: [] }; } };
  const result = await authenticate(createDeviceAuthenticator({ pool }), { token: 'short' });
  assert.equal(result.error.data.code, 'invalid_player_token');
  assert.equal(called, false);
});
