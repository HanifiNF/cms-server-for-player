<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateStorageProfiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'                    => ['type' => 'BIGINT', 'auto_increment' => true],
            'public_id'             => ['type' => 'CHAR', 'constraint' => 36],
            'name'                  => ['type' => 'VARCHAR', 'constraint' => 120],
            'driver'                => ['type' => 'VARCHAR', 'constraint' => 40],
            'status'                => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'is_default'            => ['type' => 'BOOLEAN', 'default' => false],
            'config'                => ['type' => 'TEXT'],
            'credentials_encrypted' => ['type' => 'TEXT', 'null' => true],
            'last_tested_at'        => ['type' => 'TIMESTAMP', 'null' => true],
            'last_test_status'      => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'last_test_message'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_by'            => ['type' => 'BIGINT', 'null' => true],
            'created_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'            => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_storage_profiles_public_id');
        $this->forge->addUniqueKey('name', 'uq_storage_profiles_name');
        $this->forge->addKey(['status', 'is_default'], false, false, 'idx_storage_profiles_default');
        $this->foreignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_storage_profiles_creator');
        $this->forge->createTable('storage_profiles', true);

        $this->db->table('storage_profiles')->insert([
            'public_id' => '00000000-0000-4000-8000-000000000001',
            'name' => 'Local Storage',
            'driver' => 'local',
            'status' => 'active',
            'is_default' => true,
            'config' => json_encode(['root' => 'uploads'], JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $localId = (int) $this->db->insertID();

        if ($this->db->DBDriver === 'SQLite3') {
            // SQLite supports this additive change directly. Using Forge here would
            // rebuild assets and redirect existing foreign keys to db_temp_assets.
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('assets') . ' ADD COLUMN storage_profile_id BIGINT NULL');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('asset_versions') . ' ADD COLUMN storage_profile_id BIGINT NULL');
        } else {
            $this->forge->addColumn('assets', [
                'storage_profile_id' => ['type' => 'BIGINT', 'null' => true, 'after' => 'storage_key'],
            ]);
            $this->forge->addColumn('asset_versions', [
                'storage_profile_id' => ['type' => 'BIGINT', 'null' => true, 'after' => 'storage_key'],
            ]);
        }
        $this->db->table('assets')->where('storage_profile_id', null)->update(['storage_profile_id' => $localId]);
        $this->db->table('asset_versions')->where('storage_profile_id', null)->update(['storage_profile_id' => $localId]);

        $assets = $this->db->prefixTable('assets');
        $versions = $this->db->prefixTable('asset_versions');
        $profiles = $this->db->prefixTable('storage_profiles');
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_assets_storage_profile ON {$assets} (storage_profile_id)");
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_asset_versions_storage_profile ON {$versions} (storage_profile_id)");
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->db->query("ALTER TABLE {$assets} ADD CONSTRAINT fk_assets_storage_profile FOREIGN KEY (storage_profile_id) REFERENCES {$profiles}(id) ON UPDATE CASCADE ON DELETE RESTRICT");
            $this->db->query("ALTER TABLE {$versions} ADD CONSTRAINT fk_asset_versions_storage_profile FOREIGN KEY (storage_profile_id) REFERENCES {$profiles}(id) ON UPDATE CASCADE ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('asset_versions') . ' DROP CONSTRAINT IF EXISTS fk_asset_versions_storage_profile');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('assets') . ' DROP CONSTRAINT IF EXISTS fk_assets_storage_profile');
        }
        if ($this->db->DBDriver === 'SQLite3') {
            $this->db->query('DROP INDEX IF EXISTS idx_asset_versions_storage_profile');
            $this->db->query('DROP INDEX IF EXISTS idx_assets_storage_profile');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('asset_versions') . ' DROP COLUMN storage_profile_id');
            $this->db->query('ALTER TABLE ' . $this->db->prefixTable('assets') . ' DROP COLUMN storage_profile_id');
        } else {
            $this->forge->dropColumn('asset_versions', 'storage_profile_id');
            $this->forge->dropColumn('assets', 'storage_profile_id');
        }
        $this->forge->dropTable('storage_profiles', true);
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
