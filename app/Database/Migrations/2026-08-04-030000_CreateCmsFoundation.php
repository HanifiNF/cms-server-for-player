<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateCmsFoundation extends Migration
{
    public function up(): void
    {
        $this->createUsers();
        $this->createDevices();
        $this->createAssets();
        $this->createDeviceAssets();
        $this->createSchedules();
        $this->createScheduleTargets();
        $this->createScheduleItems();
        $this->createScheduleDeliveries();
        $this->createOutboxEvents();
        $this->createAuditLogs();
    }

    public function down(): void
    {
        foreach ([
            'audit_logs',
            'outbox_events',
            'schedule_deliveries',
            'schedule_items',
            'schedule_targets',
            'schedules',
            'device_assets',
            'assets',
            'devices',
            'users',
        ] as $table) {
            $this->forge->dropTable($table, true);
        }
    }

    private function createUsers(): void
    {
        $this->forge->addField([
            'id'            => $this->id(),
            'email'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'name'          => ['type' => 'VARCHAR', 'constraint' => 120],
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'role'          => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'operator'],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'last_login_at' => ['type' => 'TIMESTAMP', 'null' => true],
            ...$this->timestamps(),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('email', 'uq_users_email');
        $this->forge->addKey('status', false, false, 'idx_users_status');
        $this->forge->createTable('users', true);
    }

    private function createDevices(): void
    {
        $this->forge->addField([
            'id'                   => $this->id(),
            'public_id'            => ['type' => 'CHAR', 'constraint' => 36],
            'name'                 => ['type' => 'VARCHAR', 'constraint' => 120],
            'device_key_hash'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'activation_code_hash' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'status'               => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'app_version'          => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'platform'             => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'timezone'             => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'Asia/Jakarta'],
            'last_seen_at'         => ['type' => 'TIMESTAMP', 'null' => true],
            'inventory_revision'   => ['type' => 'BIGINT', 'default' => 0],
            'schedule_revision'    => ['type' => 'BIGINT', 'default' => 0],
            ...$this->timestamps(),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_devices_public_id');
        $this->forge->addKey('status', false, false, 'idx_devices_status');
        $this->forge->addKey('last_seen_at', false, false, 'idx_devices_last_seen');
        $this->forge->createTable('devices', true);
    }

    private function createAssets(): void
    {
        $this->forge->addField([
            'id'          => $this->id(),
            'public_id'   => ['type' => 'CHAR', 'constraint' => 36],
            'title'       => ['type' => 'VARCHAR', 'constraint' => 255],
            'filename'    => ['type' => 'VARCHAR', 'constraint' => 255],
            'storage_key' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'mime_type'   => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'size_bytes'  => ['type' => 'BIGINT'],
            'sha256'      => ['type' => 'CHAR', 'constraint' => 64],
            'duration_ms' => ['type' => 'BIGINT', 'default' => 0],
            'status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'created_by'  => ['type' => 'BIGINT', 'null' => true],
            ...$this->timestamps(),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_assets_public_id');
        $this->forge->addKey('sha256', false, false, 'idx_assets_sha256');
        $this->forge->addKey('status', false, false, 'idx_assets_status');
        $this->foreignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_assets_created_by');
        $this->forge->createTable('assets', true);
    }

    private function createDeviceAssets(): void
    {
        $this->forge->addField([
            'id'               => $this->id(),
            'device_id'        => ['type' => 'BIGINT'],
            'asset_id'         => ['type' => 'BIGINT', 'null' => true],
            'media_key'        => ['type' => 'VARCHAR', 'constraint' => 128],
            'source'           => ['type' => 'VARCHAR', 'constraint' => 20],
            'title'            => ['type' => 'VARCHAR', 'constraint' => 255],
            'filename'         => ['type' => 'VARCHAR', 'constraint' => 255],
            'relative_path'    => ['type' => 'VARCHAR', 'constraint' => 1000, 'null' => true],
            'size_bytes'       => ['type' => 'BIGINT', 'default' => 0],
            'duration_ms'      => ['type' => 'BIGINT', 'default' => 0],
            'sha256'           => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'status'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'ready'],
            'modified_at'      => ['type' => 'TIMESTAMP', 'null' => true],
            'last_reported_at' => ['type' => 'TIMESTAMP'],
            ...$this->timestamps(),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['device_id', 'media_key'], 'uq_device_assets_device_media');
        $this->forge->addKey(['device_id', 'status'], false, false, 'idx_device_assets_status');
        $this->forge->addKey('asset_id', false, false, 'idx_device_assets_asset');
        $this->foreignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE', 'fk_device_assets_device');
        $this->foreignKey('asset_id', 'assets', 'id', 'CASCADE', 'SET NULL', 'fk_device_assets_asset');
        $this->forge->createTable('device_assets', true);
    }

    private function createSchedules(): void
    {
        $this->forge->addField([
            'id'                => $this->id(),
            'public_id'         => ['type' => 'CHAR', 'constraint' => 36],
            'title'             => ['type' => 'VARCHAR', 'constraint' => 255],
            'description'       => ['type' => 'TEXT', 'null' => true],
            'start_at'          => ['type' => 'TIMESTAMP'],
            'end_at'            => ['type' => 'TIMESTAMP'],
            'timezone'          => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'Asia/Jakarta'],
            'recurrence'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'one_time'],
            'recurrence_config' => ['type' => 'JSONB', 'null' => true],
            'status'            => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'draft'],
            'priority'          => ['type' => 'INT', 'default' => 0],
            'loop_enabled'      => ['type' => 'BOOLEAN', 'default' => true],
            'revision'          => ['type' => 'BIGINT', 'default' => 1],
            'created_by'        => ['type' => 'BIGINT', 'null' => true],
            ...$this->timestamps(),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_schedules_public_id');
        $this->forge->addKey(['status', 'start_at'], false, false, 'idx_schedules_status_start');
        $this->foreignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_schedules_created_by');
        $this->forge->createTable('schedules', true);
    }

    private function createScheduleTargets(): void
    {
        $this->forge->addField([
            'id'          => $this->id(),
            'schedule_id' => ['type' => 'BIGINT'],
            'device_id'   => ['type' => 'BIGINT'],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['schedule_id', 'device_id'], 'uq_schedule_targets_schedule_device');
        $this->forge->addKey('device_id', false, false, 'idx_schedule_targets_device');
        $this->foreignKey('schedule_id', 'schedules', 'id', 'CASCADE', 'CASCADE', 'fk_schedule_targets_schedule');
        $this->foreignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE', 'fk_schedule_targets_device');
        $this->forge->createTable('schedule_targets', true);
    }

    private function createScheduleItems(): void
    {
        $this->forge->addField([
            'id'               => $this->id(),
            'schedule_id'      => ['type' => 'BIGINT'],
            'position'         => ['type' => 'INT'],
            'asset_id'         => ['type' => 'BIGINT', 'null' => true],
            'media_key'        => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'title_snapshot'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'duration_override_ms' => ['type' => 'BIGINT', 'null' => true],
            'playback_options' => ['type' => 'JSONB', 'null' => true],
            'created_at'       => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'       => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['schedule_id', 'position'], 'uq_schedule_items_schedule_position');
        $this->forge->addKey('asset_id', false, false, 'idx_schedule_items_asset');
        $this->foreignKey('schedule_id', 'schedules', 'id', 'CASCADE', 'CASCADE', 'fk_schedule_items_schedule');
        $this->foreignKey('asset_id', 'assets', 'id', 'CASCADE', 'SET NULL', 'fk_schedule_items_asset');
        $this->forge->createTable('schedule_items', true);
    }

    private function createScheduleDeliveries(): void
    {
        $this->forge->addField([
            'id'            => $this->id(),
            'schedule_id'   => ['type' => 'BIGINT'],
            'device_id'     => ['type' => 'BIGINT'],
            'revision'      => ['type' => 'BIGINT'],
            'status'        => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'],
            'attempts'      => ['type' => 'INT', 'default' => 0],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'sent_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'acknowledged_at' => ['type' => 'TIMESTAMP', 'null' => true],
            ...$this->timestamps(),
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['schedule_id', 'device_id', 'revision'], 'uq_schedule_deliveries_revision');
        $this->forge->addKey(['device_id', 'status'], false, false, 'idx_schedule_deliveries_device_status');
        $this->foreignKey('schedule_id', 'schedules', 'id', 'CASCADE', 'CASCADE', 'fk_schedule_deliveries_schedule');
        $this->foreignKey('device_id', 'devices', 'id', 'CASCADE', 'CASCADE', 'fk_schedule_deliveries_device');
        $this->forge->createTable('schedule_deliveries', true);
    }

    private function createOutboxEvents(): void
    {
        $this->forge->addField([
            'id'             => $this->id(),
            'aggregate_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'aggregate_id'   => ['type' => 'BIGINT'],
            'event_type'     => ['type' => 'VARCHAR', 'constraint' => 100],
            'payload'        => ['type' => 'JSONB'],
            'status'         => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'attempts'       => ['type' => 'INT', 'default' => 0],
            'available_at'   => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'processed_at'   => ['type' => 'TIMESTAMP', 'null' => true],
            'last_error'     => ['type' => 'TEXT', 'null' => true],
            'created_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'     => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'available_at'], false, false, 'idx_outbox_events_pending');
        $this->forge->addKey(['aggregate_type', 'aggregate_id'], false, false, 'idx_outbox_events_aggregate');
        $this->forge->createTable('outbox_events', true);
    }

    private function createAuditLogs(): void
    {
        $this->forge->addField([
            'id'          => $this->id(),
            'user_id'     => ['type' => 'BIGINT', 'null' => true],
            'action'      => ['type' => 'VARCHAR', 'constraint' => 100],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'entity_id'   => ['type' => 'BIGINT', 'null' => true],
            'metadata'    => ['type' => 'JSONB', 'null' => true],
            'ip_address'  => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'created_at'  => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['entity_type', 'entity_id'], false, false, 'idx_audit_logs_entity');
        $this->forge->addKey('created_at', false, false, 'idx_audit_logs_created');
        $this->foreignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_audit_logs_user');
        $this->forge->createTable('audit_logs', true);
    }

    /** @return array<string, mixed> */
    private function id(): array
    {
        return ['type' => 'BIGINT', 'auto_increment' => true];
    }

    /** @return array<string, array<string, mixed>> */
    private function timestamps(): array
    {
        return [
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ];
    }

    private function foreignKey(
        string $field,
        string $referenceTable,
        string $referenceField,
        string $onUpdate,
        string $onDelete,
        string $name,
    ): void {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->forge->addForeignKey($field, $referenceTable, $referenceField, $onUpdate, $onDelete);

            return;
        }

        $this->forge->addForeignKey($field, $referenceTable, $referenceField, $onUpdate, $onDelete, $name);
    }
}
