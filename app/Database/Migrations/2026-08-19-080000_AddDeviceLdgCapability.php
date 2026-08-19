<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDeviceLdgCapability extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('devices', [
            'ldg_version' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('devices', 'ldg_version');
    }
}
