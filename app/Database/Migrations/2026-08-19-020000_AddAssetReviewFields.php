<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAssetReviewFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('assets', [
            'reviewed_by'      => ['type' => 'BIGINT', 'null' => true, 'after' => 'created_by'],
            'reviewed_at'      => ['type' => 'TIMESTAMP', 'null' => true, 'after' => 'reviewed_by'],
            'rejection_reason' => ['type' => 'TEXT', 'null' => true, 'after' => 'reviewed_at'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('assets', ['reviewed_by', 'reviewed_at', 'rejection_reason']);
    }
}
