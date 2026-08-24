'use strict';

const crypto = require('crypto');

function unauthorized(code, message) {
  const error = new Error(message);
  error.data = { code };
  return error;
}

function tokenDigest(token) {
  return crypto.createHash('sha256').update(token).digest('hex');
}

function createDeviceAuthenticator({ pool, logger }) {
  if (!pool || typeof pool.query !== 'function') throw new TypeError('A PostgreSQL pool is required.');

  return async function authenticate(socket, next) {
    try {
      const token = String(socket.handshake.auth && socket.handshake.auth.token || '').trim();
      const requestedDeviceId = String(socket.handshake.auth && socket.handshake.auth.deviceId || '').trim();
      if (token.length < 32 || token.length > 512) {
        return next(unauthorized('invalid_player_token', 'A valid Player token is required.'));
      }

      const result = await pool.query(
        `SELECT id, public_id, name, location_id, asset_revision, schedule_revision
           FROM devices
          WHERE device_key_hash = $1 AND status = 'active'
          LIMIT 1`,
        [tokenDigest(token)]
      );
      const device = result.rows[0];
      if (!device || (requestedDeviceId && requestedDeviceId !== device.public_id)) {
        return next(unauthorized('invalid_player_token', 'The Player token is invalid or inactive.'));
      }

      socket.data.device = {
        id: Number(device.id),
        publicId: String(device.public_id),
        name: String(device.name || ''),
        locationId: device.location_id === null ? null : Number(device.location_id),
        assetRevision: Math.max(0, Number(device.asset_revision) || 0),
        scheduleRevision: Math.max(0, Number(device.schedule_revision) || 0)
      };
      return next();
    } catch (error) {
      logger && logger.error('Socket authentication query failed.', { error });
      return next(unauthorized('realtime_auth_unavailable', 'Player authentication is temporarily unavailable.'));
    }
  };
}

module.exports = { createDeviceAuthenticator, tokenDigest };
