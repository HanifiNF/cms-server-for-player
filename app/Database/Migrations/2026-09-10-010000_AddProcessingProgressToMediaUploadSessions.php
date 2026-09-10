<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddProcessingProgressToMediaUploadSessions extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('media_upload_sessions', [
            'processing_stage' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true, 'after' => 'status'],
            'processing_bytes' => ['type' => 'BIGINT', 'default' => 0, 'after' => 'processing_stage'],
            'processing_total_bytes' => ['type' => 'BIGINT', 'default' => 0, 'after' => 'processing_bytes'],
            'processing_percent' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0, 'after' => 'processing_total_bytes'],
            'processing_eta_seconds' => ['type' => 'BIGINT', 'null' => true, 'after' => 'processing_percent'],
            'processing_started_at' => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'processing_eta_seconds'],
            'processing_heartbeat_at' => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'processing_started_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('media_upload_sessions', [
            'processing_stage', 'processing_bytes', 'processing_total_bytes', 'processing_percent',
            'processing_eta_seconds', 'processing_started_at', 'processing_heartbeat_at',
        ]);
    }
}
