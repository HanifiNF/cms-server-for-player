<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Realtime;
use RuntimeException;

/** Durable synchronization hints for the Node.js Socket.IO gateway. */
class RealtimeOutboxService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @param array<string, mixed> $payload */
    public function queueDevice(int $deviceId, string $eventType, array $payload = []): void
    {
        $device = $this->db->table('devices')->where('id', $deviceId)->get()->getRowArray();
        if ($device === null) return;
        $locationPublicId = null;
        if (! empty($device['location_id'])) {
            $locationPublicId = $this->db->table('locations')->select('public_id')
                ->where('id', $device['location_id'])->get()->getRowArray()['public_id'] ?? null;
        }
        $body = [
            'schema' => 'player-realtime.v1',
            'device_id' => $device['public_id'],
            'location_id' => $locationPublicId,
            'inventory_revision' => (int) ($device['inventory_revision'] ?? 0),
            'asset_revision' => (int) ($device['asset_revision'] ?? 0),
            'schedule_revision' => (int) ($device['schedule_revision'] ?? 0),
            'occurred_at' => gmdate(DATE_ATOM),
            ...$payload,
        ];
        $inserted = $this->db->table('outbox_events')->insert([
            'aggregate_type' => 'device', 'aggregate_id' => $deviceId,
            'event_type' => $eventType, 'payload' => json_encode($body, JSON_THROW_ON_ERROR),
            'status' => 'pending', 'attempts' => 0,
            'available_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if (! $inserted) throw new RuntimeException('Realtime outbox event could not be queued.');

        $this->notifyGateway();
    }

    private function notifyGateway(): void
    {
        $config = config(Realtime::class);
        if (! $config->enabled || strtolower((string) $this->db->DBDriver) !== 'postgre') return;

        // PostgreSQL delivers NOTIFY only after the surrounding transaction
        // commits. The durable outbox remains authoritative if no listener is
        // connected when the notification is published.
        $this->db->query('SELECT pg_notify(?, ?)', [$config->notificationChannel, 'outbox']);
    }
}
