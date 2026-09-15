<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateExternalEncryptionJobs extends Migration
{
    public function up(): void
    {
        $delivery = ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'remote'];
        if ($this->db->DBDriver === 'SQLite3') {
            // Forge rebuilds SQLite tables and can redirect existing foreign keys to
            // db_temp_assets. A native additive column keeps those references intact.
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('assets') . " ADD COLUMN delivery_mode VARCHAR(20) NOT NULL DEFAULT 'remote'");
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('asset_versions') . " ADD COLUMN delivery_mode VARCHAR(20) NOT NULL DEFAULT 'remote'");
        } else {
            $this->forge->addColumn('assets', ['delivery_mode' => $delivery]);
            $this->forge->addColumn('asset_versions', ['delivery_mode' => $delivery]);
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'auto_increment' => true],
            'public_id' => ['type' => 'CHAR', 'constraint' => 36],
            'owner_user_id' => ['type' => 'BIGINT'],
            'purpose' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'asset'],
            'target_asset_id' => ['type' => 'BIGINT', 'null' => true],
            'result_public_id' => ['type' => 'CHAR', 'constraint' => 36],
            'revision' => ['type' => 'INTEGER', 'default' => 1],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'metadata_json' => ['type' => 'TEXT'],
            'wrapped_dek' => ['type' => 'TEXT', 'null' => true],
            'dek_nonce' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'dek_tag' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'key_version' => ['type' => 'INTEGER', 'default' => 1],
            'job_token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'progress_stage' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'processed_bytes' => ['type' => 'BIGINT', 'default' => 0],
            'total_bytes' => ['type' => 'BIGINT', 'default' => 0],
            'progress_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'filename' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
            'plaintext_size_bytes' => ['type' => 'BIGINT', 'null' => true],
            'plaintext_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'ldg_chunk_size' => ['type' => 'BIGINT', 'null' => true],
            'output_size_bytes' => ['type' => 'BIGINT', 'null' => true],
            'output_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'duration_ms' => ['type' => 'BIGINT', 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'expires_at' => ['type' => 'TIMESTAMP'],
            'completed_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_external_encryption_jobs_public_id');
        $this->forge->addKey(['owner_user_id', 'status'], false, false, 'idx_external_encryption_owner');
        $this->forge->addKey(['status', 'expires_at'], false, false, 'idx_external_encryption_expiry');
        $this->foreignKey('owner_user_id', 'users', 'id', 'CASCADE', 'CASCADE', 'fk_external_encryption_owner');
        $this->foreignKey('target_asset_id', 'assets', 'id', 'CASCADE', 'CASCADE', 'fk_external_encryption_asset');
        $this->forge->createTable('external_encryption_jobs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('external_encryption_jobs', true);
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('asset_versions') . ' DROP COLUMN delivery_mode');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('assets') . ' DROP COLUMN delivery_mode');
        } else {
            $this->forge->dropColumn('asset_versions', 'delivery_mode');
            $this->forge->dropColumn('assets', 'delivery_mode');
        }
    }

    private function foreignKey(string $field, string $table, string $reference, string $onUpdate, string $onDelete, string $name): void
    {
        if ($this->db->DBDriver === 'SQLite3') $this->forge->addForeignKey($field, $table, $reference, $onUpdate, $onDelete);
        else $this->forge->addForeignKey($field, $table, $reference, $onUpdate, $onDelete, $name);
    }
}
