'use strict';

function retryDelay(attempts, baseMs, maximumMs) {
  return Math.min(maximumMs, baseMs * (2 ** Math.max(0, attempts - 1)));
}

class OutboxWorker {
  constructor({ repository, publisher, logger, pollMs = 1000, batchSize = 50, maxAttempts = 12,
    retryBaseMs = 1000, retryMaxMs = 60000 }) {
    this.repository = repository;
    this.publisher = publisher;
    this.logger = logger;
    this.pollMs = pollMs;
    this.batchSize = batchSize;
    this.maxAttempts = maxAttempts;
    this.retryBaseMs = retryBaseMs;
    this.retryMaxMs = retryMaxMs;
    this.running = false;
    this.processing = false;
    this.runAgain = false;
    this.pollTimer = null;
    this.listenerRetryTimer = null;
    this.closeListener = null;
    this.stats = { processed: 0, failed: 0, retried: 0, lastProcessedAt: null, lastError: null };
  }

  async start() {
    if (this.running) return;
    this.running = true;
    const recovered = await this.repository.recoverStale();
    if (recovered) this.logger.warn('Recovered stale realtime outbox events.', { count: recovered });
    this.pollTimer = setInterval(() => void this.wake(), this.pollMs);
    if (this.pollTimer.unref) this.pollTimer.unref();
    void this.connectListener();
    await this.wake();
  }

  async stop() {
    this.running = false;
    if (this.pollTimer) clearInterval(this.pollTimer);
    if (this.listenerRetryTimer) clearTimeout(this.listenerRetryTimer);
    this.pollTimer = null;
    this.listenerRetryTimer = null;
    if (this.closeListener) await this.closeListener().catch(() => {});
    this.closeListener = null;
    while (this.processing) await new Promise(resolve => setTimeout(resolve, 20));
  }

  async connectListener() {
    if (!this.running || this.closeListener) return;
    try {
      this.closeListener = await this.repository.listen(
        () => void this.wake(),
        error => {
          this.logger.warn('PostgreSQL realtime listener disconnected; polling remains active.', { error });
          const close = this.closeListener;
          this.closeListener = null;
          void Promise.resolve(close ? close() : undefined).finally(() => this.scheduleListenerReconnect());
        }
      );
      this.logger.info('Listening for PostgreSQL outbox notifications.');
    } catch (error) {
      this.logger.warn('Could not start PostgreSQL outbox listener; polling remains active.', { error });
      this.scheduleListenerReconnect();
    }
  }

  scheduleListenerReconnect() {
    if (!this.running || this.listenerRetryTimer) return;
    this.listenerRetryTimer = setTimeout(() => {
      this.listenerRetryTimer = null;
      void this.connectListener();
    }, 5000);
    if (this.listenerRetryTimer.unref) this.listenerRetryTimer.unref();
  }

  async wake() {
    if (!this.running) return;
    if (this.processing) {
      this.runAgain = true;
      return;
    }
    this.processing = true;
    try {
      do {
        this.runAgain = false;
        const events = await this.repository.claim(this.batchSize);
        for (const event of events) await this.process(event);
        if (events.length === this.batchSize) this.runAgain = true;
      } while (this.running && this.runAgain);
    } catch (error) {
      this.stats.lastError = error.message || String(error);
      this.logger.error('Realtime outbox cycle failed.', { error });
    } finally {
      this.processing = false;
    }
  }

  async process(event) {
    try {
      const result = await this.publisher.publish(event);
      await this.repository.complete(event.id);
      this.stats.processed += 1;
      this.stats.lastProcessedAt = new Date().toISOString();
      this.stats.lastError = null;
      this.logger.debug('Realtime outbox event processed.', {
        eventId: Number(event.id), eventType: event.event_type,
        deviceId: result.deviceId, connected: result.connected
      });
    } catch (error) {
      const attempts = Math.max(1, Number(event.attempts) || 1);
      const terminal = attempts >= this.maxAttempts;
      const delay = retryDelay(attempts, this.retryBaseMs, this.retryMaxMs);
      await this.repository.fail(event.id, error, delay, terminal);
      if (terminal) this.stats.failed += 1; else this.stats.retried += 1;
      this.stats.lastError = error.message || String(error);
      this.logger[terminal ? 'error' : 'warn']('Realtime outbox publish failed.', {
        eventId: Number(event.id), attempts, terminal, retryDelayMs: terminal ? null : delay, error
      });
    }
  }
}

module.exports = { OutboxWorker, retryDelay };
