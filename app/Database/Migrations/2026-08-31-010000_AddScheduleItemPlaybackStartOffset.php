<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScheduleItemPlaybackStartOffset extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('schedule_items', [
            'playback_start_offset_ms' => [
                'type' => 'BIGINT',
                'default' => 0,
                'null' => false,
                'after' => 'duration_override_ms',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('schedule_items', 'playback_start_offset_ms');
    }
}
