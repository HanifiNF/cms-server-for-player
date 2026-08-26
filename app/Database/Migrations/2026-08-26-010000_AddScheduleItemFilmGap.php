<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScheduleItemFilmGap extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('schedule_items', [
            'gap_after_ms' => ['type' => 'BIGINT', 'default' => 0, 'null' => false, 'after' => 'duration_override_ms'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('schedule_items', 'gap_after_ms');
    }
}
