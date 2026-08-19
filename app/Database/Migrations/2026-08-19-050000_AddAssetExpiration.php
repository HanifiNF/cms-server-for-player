<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAssetExpiration extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('assets', [
            'expires_on' => ['type' => 'DATE', 'null' => true, 'after' => 'release_date'],
            'expired_at' => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'expires_on'],
        ]);
        $this->forge->addColumn('devices', [
            'asset_revision' => ['type' => 'BIGINT', 'default' => 0, 'after' => 'inventory_revision'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('devices', 'asset_revision');
        $this->forge->dropColumn('assets', ['expires_on', 'expired_at']);
    }
}
