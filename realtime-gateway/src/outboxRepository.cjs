'use strict';

class OutboxRepository {
  constructor({ pool, channel, staleProcessingMs = 60000 }) {
    this.pool = pool;
    this.channel = channel;
    this.staleProcessingMs = staleProcessingMs;
  }

  async recoverStale() {
    const result = await this.pool.query(
      `UPDATE outbox_events
          SET status = 'pending', available_at = NOW(),
              last_error = 'Recovered after realtime gateway interruption', updated_at = NOW()
        WHERE status = 'processing'
          AND aggregate_type = 'device'
          AND updated_at < NOW() - ($1::bigint * INTERVAL '1 millisecond')`,
      [this.staleProcessingMs]
    );
    return result.rowCount || 0;
  }

  async claim(limit) {
    const client = await this.pool.connect();
    try {
      await client.query('BEGIN');
      const result = await client.query(
        `WITH candidates AS (
           SELECT id
             FROM outbox_events
            WHERE aggregate_type = 'device'
              AND status = 'pending' AND available_at <= NOW()
            ORDER BY id
            FOR UPDATE SKIP LOCKED
            LIMIT $1
         )
         UPDATE outbox_events AS events
            SET status = 'processing', attempts = events.attempts + 1, updated_at = NOW()
           FROM candidates
          WHERE events.id = candidates.id
        RETURNING events.id, events.aggregate_type, events.aggregate_id,
                  events.event_type, events.payload, events.attempts, events.created_at`,
        [limit]
      );
      await client.query('COMMIT');
      return result.rows;
    } catch (error) {
      await client.query('ROLLBACK').catch(() => {});
      throw error;
    } finally {
      client.release();
    }
  }

  async complete(id) {
    await this.pool.query(
      `UPDATE outbox_events
          SET status = 'processed', processed_at = NOW(), last_error = NULL, updated_at = NOW()
        WHERE id = $1 AND status = 'processing'`,
      [id]
    );
  }

  async fail(id, error, retryDelayMs, terminal) {
    await this.pool.query(
      `UPDATE outbox_events
          SET status = $2,
              available_at = CASE WHEN $2 = 'pending'
                THEN NOW() + ($3::bigint * INTERVAL '1 millisecond') ELSE available_at END,
              last_error = $4,
              updated_at = NOW()
        WHERE id = $1 AND status = 'processing'`,
      [id, terminal ? 'failed' : 'pending', retryDelayMs, String(error && error.message || error).slice(0, 2000)]
    );
  }

  async listen(onNotification, onError) {
    const client = await this.pool.connect();
    const handleError = error => onError(error);
    client.on('notification', message => {
      if (message.channel === this.channel) onNotification(message.payload || '');
    });
    client.on('error', handleError);
    try {
      await client.query(`LISTEN ${this.channel}`);
    } catch (error) {
      client.removeListener('error', handleError);
      client.release(error);
      throw error;
    }
    return async () => {
      client.removeListener('error', handleError);
      await client.query(`UNLISTEN ${this.channel}`).catch(() => {});
      client.release();
    };
  }
}

module.exports = { OutboxRepository };
