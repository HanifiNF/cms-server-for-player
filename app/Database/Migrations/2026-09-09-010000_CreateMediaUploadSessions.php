<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateMediaUploadSessions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'auto_increment' => true],
            'public_id' => ['type' => 'CHAR', 'constraint' => 36],
            'owner_user_id' => ['type' => 'BIGINT'],
            'purpose' => ['type' => 'VARCHAR', 'constraint' => 20],
            'target_asset_id' => ['type' => 'BIGINT', 'null' => true],
            'fingerprint' => ['type' => 'CHAR', 'constraint' => 64],
            'filename' => ['type' => 'VARCHAR', 'constraint' => 255],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 160],
            'size_bytes' => ['type' => 'BIGINT'],
            'last_modified_ms' => ['type' => 'BIGINT', 'default' => 0],
            'chunk_size_bytes' => ['type' => 'BIGINT'],
            'received_bytes' => ['type' => 'BIGINT', 'default' => 0],
            'next_chunk_index' => ['type' => 'INTEGER', 'default' => 0],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'uploading'],
            'staging_name' => ['type' => 'VARCHAR', 'constraint' => 100],
            'poster_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'metadata_json' => ['type' => 'TEXT'],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'result_public_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true],
            'expires_at' => ['type' => 'TIMESTAMP'],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_media_upload_sessions_public_id');
        $this->forge->addKey(['owner_user_id', 'fingerprint'], false, false, 'idx_media_upload_resume');
        $this->forge->addKey(['status', 'expires_at'], false, false, 'idx_media_upload_expiry');
        $this->foreignKey('owner_user_id', 'users', 'id', 'CASCADE', 'CASCADE', 'fk_media_upload_owner');
        $this->foreignKey('target_asset_id', 'assets', 'id', 'CASCADE', 'CASCADE', 'fk_media_upload_asset');
        $this->forge->createTable('media_upload_sessions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('media_upload_sessions', true);
    }

    private function foreignKey(string $field, string $table, string $reference, string $onUpdate, string $onDelete, string $name): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->forge->addForeignKey($field, $table, $reference, $onUpdate, $onDelete);
            return;
        }
        $this->forge->addForeignKey($field, $table, $reference, $onUpdate, $onDelete, $name);
    }
}
