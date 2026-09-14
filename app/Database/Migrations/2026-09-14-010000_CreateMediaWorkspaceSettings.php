<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateMediaWorkspaceSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'INTEGER', 'auto_increment' => true],
            'workspace_id' => ['type' => 'CHAR', 'constraint' => 36],
            'root_path' => ['type' => 'VARCHAR', 'constraint' => 1024],
            'last_tested_at' => ['type' => 'TIMESTAMP', 'null' => true],
            'last_test_status' => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
            'last_test_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'configured_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('workspace_id', 'uq_media_workspace_settings_workspace_id');
        $this->foreignKey('configured_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_media_workspace_configured_by');
        $this->forge->createTable('media_workspace_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('media_workspace_settings', true);
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
