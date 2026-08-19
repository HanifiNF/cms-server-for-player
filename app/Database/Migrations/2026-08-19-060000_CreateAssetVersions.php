<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateAssetVersions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('assets', [
            'revision' => ['type' => 'INT', 'default' => 1, 'after' => 'public_id'],
        ]);
        $this->forge->addField([
            'id'                 => ['type' => 'BIGINT', 'auto_increment' => true],
            'asset_id'           => ['type' => 'BIGINT'],
            'revision'           => ['type' => 'INT'],
            'filename'           => ['type' => 'VARCHAR', 'constraint' => 255],
            'storage_key'        => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'mime_type'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'size_bytes'         => ['type' => 'BIGINT'],
            'sha256'             => ['type' => 'CHAR', 'constraint' => 64],
            'duration_ms'        => ['type' => 'BIGINT', 'default' => 0],
            'status'             => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'metadata_snapshot'  => ['type' => 'TEXT', 'null' => true],
            'submitted_by'       => ['type' => 'BIGINT', 'null' => true],
            'reviewed_by'        => ['type' => 'BIGINT', 'null' => true],
            'reviewed_at'        => ['type' => 'TIMESTAMP', 'null' => true],
            'rejection_reason'   => ['type' => 'TEXT', 'null' => true],
            'created_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'         => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['asset_id', 'revision'], 'uq_asset_versions_asset_revision');
        $this->forge->addKey(['asset_id', 'status'], false, false, 'idx_asset_versions_status');
        $this->foreignKey('asset_id', 'assets', 'id', 'CASCADE', 'CASCADE', 'fk_asset_versions_asset');
        $this->foreignKey('submitted_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_asset_versions_submitter');
        $this->foreignKey('reviewed_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_asset_versions_reviewer');
        $this->forge->createTable('asset_versions', true);

        foreach ($this->db->table('assets')->get()->getResultArray() as $asset) {
            $this->db->table('asset_versions')->insert([
                'asset_id' => $asset['id'], 'revision' => 1,
                'filename' => $asset['filename'], 'storage_key' => $asset['storage_key'],
                'mime_type' => $asset['mime_type'], 'size_bytes' => $asset['size_bytes'],
                'sha256' => $asset['sha256'], 'duration_ms' => $asset['duration_ms'],
                'status' => $this->versionStatus((string) $asset['status']),
                'metadata_snapshot' => $this->metadataSnapshot($asset),
                'submitted_by' => $asset['created_by'], 'reviewed_by' => $asset['reviewed_by'] ?? null,
                'reviewed_at' => $asset['reviewed_at'] ?? null,
                'rejection_reason' => $asset['rejection_reason'] ?? null,
                'created_at' => $asset['created_at'], 'updated_at' => $asset['updated_at'],
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('asset_versions', true);
        $this->forge->dropColumn('assets', 'revision');
    }

    /** @param array<string, mixed> $asset */
    private function metadataSnapshot(array $asset): string
    {
        $fields = ['title', 'synopsis', 'genre', 'language', 'subtitles', 'age_rating', 'production_year', 'release_date', 'expires_on', 'distributor_company'];
        $snapshot = [];
        foreach ($fields as $field) $snapshot[$field] = $asset[$field] ?? null;
        return json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function versionStatus(string $assetStatus): string
    {
        return match ($assetStatus) {
            'active' => 'approved',
            'rejected' => 'rejected',
            'expired' => 'expired',
            default => 'draft',
        };
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
