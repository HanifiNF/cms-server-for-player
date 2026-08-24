'use strict';

const LEVELS = { debug: 10, info: 20, warn: 30, error: 40 };

function safeError(error) {
  if (!error) return undefined;
  return { name: error.name || 'Error', message: error.message || String(error), code: error.code || undefined };
}

function createLogger(level = 'info', output = console) {
  const threshold = LEVELS[level] || LEVELS.info;
  const write = (name, message, fields = {}) => {
    if (LEVELS[name] < threshold) return;
    const record = { timestamp: new Date().toISOString(), level: name, message, ...fields };
    if (record.error instanceof Error) record.error = safeError(record.error);
    const method = name === 'error' ? 'error' : (name === 'warn' ? 'warn' : 'log');
    output[method](JSON.stringify(record));
  };
  return {
    debug: (message, fields) => write('debug', message, fields),
    info: (message, fields) => write('info', message, fields),
    warn: (message, fields) => write('warn', message, fields),
    error: (message, fields) => write('error', message, fields)
  };
}

module.exports = { createLogger };
