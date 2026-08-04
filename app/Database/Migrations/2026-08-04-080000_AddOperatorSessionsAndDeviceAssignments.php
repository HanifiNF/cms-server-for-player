<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class AddOperatorSessionsAndDeviceAssignments extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('devices', [
            'assigned_user_id' => ['type' => 'BIGINT', 'null' => true],
            'claimed_by'       => ['type' => 'BIGINT', 'null' => true],
            'claimed_at'       => ['type' => 'TIMESTAMP', 'null' => true],
            'location'         => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
        ]);

        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'auto_increment' => true],
            'public_id'     => ['type' => 'CHAR', 'constraint' => 36],
            'user_id'       => ['type' => 'BIGINT'],
            'token_hash'    => ['type' => 'CHAR', 'constraint' => 64],
            'expires_at'    => ['type' => 'TIMESTAMP'],
            'last_used_at'  => ['type' => 'TIMESTAMP', 'null' => true],
            'revoked_at'    => ['type' => 'TIMESTAMP', 'null' => true],
            'ip_address'    => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent'    => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at'    => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at'    => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_auth_sessions_public_id');
        $this->forge->addUniqueKey('token_hash', 'uq_auth_sessions_token_hash');
        $this->forge->addKey(['user_id', 'expires_at'], false, false, 'idx_auth_sessions_user_expiry');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('auth_sessions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('auth_sessions', true);
        $this->forge->dropColumn('devices', ['assigned_user_id', 'claimed_by', 'claimed_at', 'location']);
    }
}
