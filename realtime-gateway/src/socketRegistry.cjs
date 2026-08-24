'use strict';

function deviceRoom(publicId) {
  return `device:${publicId}`;
}

class SocketRegistry {
  constructor({ logger } = {}) {
    this.logger = logger;
    this.devices = new Map();
  }

  register(socket) {
    const publicId = socket.data.device.publicId;
    const previous = this.devices.get(publicId);
    if (previous && previous.id !== socket.id) {
      previous.emit('session:replaced', { reason: 'A newer Player connection replaced this session.' });
      previous.disconnect(true);
    }
    this.devices.set(publicId, socket);
    socket.join(deviceRoom(publicId));
    socket.once('disconnect', () => {
      if (this.devices.get(publicId) === socket) this.devices.delete(publicId);
    });
    this.logger && this.logger.info('Player socket connected.', { deviceId: publicId, socketId: socket.id });
  }

  get(publicId) {
    return this.devices.get(publicId) || null;
  }

  has(publicId) {
    return this.devices.has(publicId);
  }

  disconnect(publicId, event, payload) {
    const socket = this.get(publicId);
    if (!socket) return false;
    socket.emit(event, payload);
    const timer = setTimeout(() => socket.disconnect(true), 25);
    if (timer.unref) timer.unref();
    return true;
  }

  get size() {
    return this.devices.size;
  }
}

module.exports = { SocketRegistry, deviceRoom };
