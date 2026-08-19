<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLdgEncryption extends Migration
{
    public function up(): void
    {
        $fields = [
            'encryption_format' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
            'plaintext_size_bytes' => ['type' => 'BIGINT', 'null' => true],
            'plaintext_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'ldg_chunk_size' => ['type' => 'INT', 'null' => true],
            'wrapped_dek' => ['type' => 'TEXT', 'null' => true],
            'dek_nonce' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'dek_tag' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'key_version' => ['type' => 'INT', 'default' => 1],
            'encryption_revision' => ['type' => 'INT', 'null' => true],
        ];
        $this->forge->addColumn('assets', $fields);
        $this->forge->addColumn('asset_versions', $fields);
    }

    public function down(): void
    {
        foreach (['encryption_format', 'plaintext_size_bytes', 'plaintext_sha256', 'ldg_chunk_size',
            'wrapped_dek', 'dek_nonce', 'dek_tag', 'key_version', 'encryption_revision'] as $field) {
            $this->forge->dropColumn('asset_versions', $field);
            $this->forge->dropColumn('assets', $field);
        }
    }
}
