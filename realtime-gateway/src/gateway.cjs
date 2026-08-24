'use strict';

const http = require('http');
const { Server } = require('socket.io');
const { SocketRegistry, deviceRoom } = require('./socketRegistry.cjs');

const REVOKE_EVENTS = new Set(['studio.revoked', 'studio.pairing.reset', 'studio.deleted']);

function payloadOf(event) {
  if (event.payload && typeof event.payload === 'object') return event.payload;
  if (typeof event.payload === 'string') return JSON.parse(event.payload);
  throw new Error(`Outbox event ${event.id} has an invalid payload.`);
}

function toRealtimeMessage(event) {
  const payload = payloadOf(event);
  const deviceId = String(payload.device_id || '').trim();
  if (!deviceId) throw new Error(`Outbox event ${event.id} has no device_id.`);
  return {
    schema: 'player-realtime.v1',
    eventId: Number(event.id),
    deviceId,
    inventoryRevision: Math.max(0, Number(payload.inventory_revision) || 0),
    assetRevision: Math.max(0, Number(payload.asset_revision) || 0),
    scheduleRevision: Math.max(0, Number(payload.schedule_revision) || 0),
    reason: String(event.event_type || 'revision.changed'),
    occurredAt: String(payload.occurred_at || event.created_at || new Date().toISOString())
  };
}

function createGateway({ config, pool, authenticate, logger, workerStats = () => ({}) }) {
  const registry = new SocketRegistry({ logger });
  const startedAt = Date.now();
  let workerRunning = false;

  const requestHandler = async (request, response) => {
    if (request.method === 'GET' && request.url === '/live') {
      response.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      return response.end(JSON.stringify({ status: 'ok', service: 'realtime-gateway' }));
    }
    if (request.method === 'GET' && request.url === '/health') {
      try {
        await pool.query('SELECT 1');
        response.writeHead(workerRunning ? 200 : 503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
        return response.end(JSON.stringify({
          status: workerRunning ? 'ready' : 'starting', database: 'available',
          connectedPlayers: registry.size, uptimeSeconds: Math.floor((Date.now() - startedAt) / 1000),
          outbox: workerStats()
        }));
      } catch (_) {
        response.writeHead(503, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
        return response.end(JSON.stringify({ status: 'unavailable', database: 'unavailable' }));
      }
    }
    response.writeHead(404, { 'Content-Type': 'application/json' });
    response.end(JSON.stringify({ error: 'not_found' }));
  };

  const server = http.createServer((request, response) => void requestHandler(request, response));
  const io = new Server(server, {
    path: config.socketPath,
    cors: { origin: config.corsOrigins, methods: ['GET', 'POST'] },
    maxHttpBufferSize: 64 * 1024,
    serveClient: false,
    transports: ['websocket', 'polling']
  });
  const player = io.of('/player');
  player.use(authenticate);
  player.on('connection', socket => {
    registry.register(socket);
    const device = socket.data.device;
    socket.emit('sync:initial', {
      schema: 'player-realtime.v1', eventId: null, deviceId: device.publicId,
      assetRevision: device.assetRevision, scheduleRevision: device.scheduleRevision,
      reason: 'socket.connected', occurredAt: new Date().toISOString()
    });
    socket.on('sync:applied', acknowledgement => {
      if (!acknowledgement || typeof acknowledgement !== 'object') return;
      logger.info('Player reported realtime revision applied.', {
        deviceId: device.publicId,
        eventId: Number(acknowledgement.eventId) || null,
        assetRevision: Math.max(0, Number(acknowledgement.assetRevision) || 0),
        scheduleRevision: Math.max(0, Number(acknowledgement.scheduleRevision) || 0)
      });
    });
    socket.on('disconnect', reason => logger.info('Player socket disconnected.', { deviceId: device.publicId, reason }));
  });

  const publisher = {
    async publish(event) {
      const message = toRealtimeMessage(event);
      const connected = registry.has(message.deviceId);
      if (REVOKE_EVENTS.has(message.reason)) {
        registry.disconnect(message.deviceId, 'device:revoked', {
          schema: message.schema, eventId: message.eventId,
          deviceId: message.deviceId, reason: message.reason, occurredAt: message.occurredAt
        });
      } else {
        player.to(deviceRoom(message.deviceId)).emit('sync:hint', message);
      }
      return { connected, deviceId: message.deviceId };
    }
  };

  return {
    server, io, player, registry, publisher,
    setWorkerRunning(value) { workerRunning = Boolean(value); },
    async start() {
      await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(config.port, config.host, () => {
          server.removeListener('error', reject);
          resolve();
        });
      });
      return server.address();
    },
    async stop() {
      await new Promise(resolve => io.close(() => resolve()));
      if (server.listening) await new Promise(resolve => server.close(() => resolve()));
    }
  };
}

module.exports = { createGateway, toRealtimeMessage };
