<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDeviceEnrollmentFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('devices', [
            'activation_expires_at' => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'activation_code_hash'],
            'fingerprint_hash'      => ['type' => 'CHAR', 'constraint' => 64, 'null' => true, 'after' => 'activation_expires_at'],
            'registered_at'         => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'last_seen_at'],
            'token_last_used_at'    => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'registered_at'],
            'ip_address'            => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true, 'after' => 'token_last_used_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('devices', [
            'activation_expires_at',
            'fingerprint_hash',
            'registered_at',
            'token_last_used_at',
            'ip_address',
        ]);
    }
}
