<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnforceSingleDefaultStorageProfile extends Migration
{
    private const INDEX = 'uq_storage_profiles_single_default';

    public function up(): void
    {
        $table = $this->db->prefixTable('storage_profiles');

        // Retain the oldest default if legacy data ever contains duplicates.
        $this->db->query(
            "UPDATE {$table} SET is_default = FALSE "
            . "WHERE is_default = TRUE AND id NOT IN "
            . "(SELECT id FROM {$table} WHERE is_default = TRUE ORDER BY id ASC LIMIT 1)"
        );

        if (in_array($this->db->DBDriver, ['Postgre', 'SQLite3'], true)) {
            $this->db->query(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::INDEX
                . " ON {$table} (is_default) WHERE is_default = TRUE"
            );
        }
    }

    public function down(): void
    {
        if (in_array($this->db->DBDriver, ['Postgre', 'SQLite3'], true)) {
            $this->db->query('DROP INDEX IF EXISTS ' . self::INDEX);
        }
    }
}
