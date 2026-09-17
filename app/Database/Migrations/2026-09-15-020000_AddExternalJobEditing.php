<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AddExternalJobEditing extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('external_encryption_jobs', [
            'edit_version' => ['type' => 'INTEGER', 'default' => 1],
            'creation_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        ]);
        $this->db->query('CREATE UNIQUE INDEX uq_external_job_creation ON ' . $this->db->prefixTable('external_encryption_jobs') . ' (owner_user_id, creation_key)');
    }
    public function down(): void
    {
        $this->db->query('DROP INDEX uq_external_job_creation');
        $this->forge->dropColumn('external_encryption_jobs', ['edit_version', 'creation_key']);
    }
}
