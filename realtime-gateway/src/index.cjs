'use strict';

const { Pool } = require('pg');
const { loadConfig } = require('./config.cjs');
const { createLogger } = require('./logger.cjs');
const { createDeviceAuthenticator } = require('./deviceAuth.cjs');
const { OutboxRepository } = require('./outboxRepository.cjs');
const { OutboxWorker } = require('./outboxWorker.cjs');
const { createGateway } = require('./gateway.cjs');

async function main() {
  const config = loadConfig();
  const logger = createLogger(config.logLevel);
  const pool = new Pool({ ...config.database, application_name: 'wir-realtime-gateway', max: 12 });
  pool.on('error', error => logger.error('Unexpected PostgreSQL pool error.', { error }));

  await pool.query('SELECT 1');
  const repository = new OutboxRepository({
    pool, channel: config.outboxChannel, staleProcessingMs: config.outboxStaleProcessingMs
  });
  let worker;
  const gateway = createGateway({
    config, pool, logger,
    authenticate: createDeviceAuthenticator({ pool, logger }),
    workerStats: () => worker ? worker.stats : {}
  });
  worker = new OutboxWorker({
    repository, publisher: gateway.publisher, logger,
    pollMs: config.outboxPollMs, batchSize: config.outboxBatchSize,
    maxAttempts: config.outboxMaxAttempts,
    retryBaseMs: config.outboxRetryBaseMs, retryMaxMs: config.outboxRetryMaxMs
  });

  const address = await gateway.start();
  await worker.start();
  gateway.setWorkerRunning(true);
  logger.info('Realtime gateway started.', {
    host: address.address, port: address.port, socketPath: config.socketPath,
    namespace: '/player', outboxChannel: config.outboxChannel
  });

  let shuttingDown = false;
  const shutdown = async signal => {
    if (shuttingDown) return;
    shuttingDown = true;
    logger.info('Realtime gateway stopping.', { signal });
    gateway.setWorkerRunning(false);
    const timeout = setTimeout(() => process.exit(1), config.shutdownTimeoutMs);
    if (timeout.unref) timeout.unref();
    await worker.stop();
    await gateway.stop();
    await pool.end();
    clearTimeout(timeout);
    logger.info('Realtime gateway stopped.');
  };
  process.once('SIGINT', () => void shutdown('SIGINT'));
  process.once('SIGTERM', () => void shutdown('SIGTERM'));
}

main().catch(error => {
  console.error(JSON.stringify({ timestamp: new Date().toISOString(), level: 'error', message: 'Realtime gateway failed to start.', error: { message: error.message, code: error.code } }));
  process.exitCode = 1;
});
