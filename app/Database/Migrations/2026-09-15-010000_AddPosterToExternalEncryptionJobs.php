<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPosterToExternalEncryptionJobs extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('external_encryption_jobs', [
            'poster_name' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'after' => 'metadata_json'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('external_encryption_jobs', 'poster_name');
    }
}
