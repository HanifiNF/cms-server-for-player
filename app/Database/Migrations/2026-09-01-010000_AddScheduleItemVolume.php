<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScheduleItemVolume extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('schedule_items', [
            'volume_percent' => [
                'type' => 'SMALLINT',
                'default' => 100,
                'null' => false,
                'after' => 'gap_after_ms',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('schedule_items', 'volume_percent');
    }
}
