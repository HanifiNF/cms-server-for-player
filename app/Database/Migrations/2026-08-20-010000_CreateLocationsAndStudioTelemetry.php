<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateLocationsAndStudioTelemetry extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id'         => ['type' => 'BIGINT', 'auto_increment' => true],
            'public_id'  => ['type' => 'CHAR', 'constraint' => 36],
            'name'       => ['type' => 'VARCHAR', 'constraint' => 160],
            'code'       => ['type' => 'VARCHAR', 'constraint' => 24],
            'address'    => ['type' => 'TEXT', 'null' => true],
            'timezone'   => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'Asia/Jakarta'],
            'status'     => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_locations_public_id');
        $this->forge->addUniqueKey('code', 'uq_locations_code');
        $this->forge->addKey(['status', 'name'], false, false, 'idx_locations_status_name');
        $this->forge->createTable('locations', true);

        $this->forge->addColumn('devices', [
            'location_id'          => ['type' => 'BIGINT', 'null' => true],
            'playback_state'       => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'unknown'],
            'playback_schedule_id' => ['type' => 'CHAR', 'constraint' => 36, 'null' => true],
            'playback_error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'playback_updated_at'  => ['type' => 'TIMESTAMP', 'null' => true],
        ]);

        $devicesTable = $this->db->prefixTable('devices');
        $locationsTable = $this->db->prefixTable('locations');
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_devices_location ON {$devicesTable} (location_id)");
        $this->db->query("CREATE INDEX IF NOT EXISTS idx_devices_playback_state ON {$devicesTable} (playback_state)");
        if ($this->db->DBDriver !== 'SQLite3') {
            $this->db->query(
                "ALTER TABLE {$devicesTable} ADD CONSTRAINT fk_devices_location "
                . "FOREIGN KEY (location_id) REFERENCES {$locationsTable}(id) ON UPDATE CASCADE ON DELETE RESTRICT"
            );
        }

        $this->migrateLegacyLocations();
    }

    public function down(): void
    {
        if ($this->db->DBDriver !== 'SQLite3') {
            $devicesTable = $this->db->prefixTable('devices');
            $this->db->query("ALTER TABLE {$devicesTable} DROP CONSTRAINT IF EXISTS fk_devices_location");
        }
        $this->forge->dropColumn('devices', [
            'location_id', 'playback_state', 'playback_schedule_id',
            'playback_error', 'playback_updated_at',
        ]);
        $this->forge->dropTable('locations', true);
    }

    private function migrateLegacyLocations(): void
    {
        $rows = $this->db->table('devices')
            ->select('location')
            ->where('location IS NOT NULL', null, false)
            ->where('location !=', '')
            ->groupBy('location')
            ->get()->getResultArray();

        $usedCodes = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['location'] ?? ''));
            if ($name === '') continue;

            $baseCode = $this->locationCode($name);
            $code = $baseCode;
            $suffix = 2;
            while (isset($usedCodes[$code]) || $this->db->table('locations')->where('code', $code)->countAllResults() > 0) {
                $code = mb_substr($baseCode, 0, 20) . '-' . $suffix++;
            }
            $usedCodes[$code] = true;

            $this->db->table('locations')->insert([
                'public_id' => $this->uuidV4(),
                'name' => $name,
                'code' => $code,
                'timezone' => 'Asia/Jakarta',
                'status' => 'active',
                'created_at' => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $locationId = (int) $this->db->insertID();
            $this->db->table('devices')->where('location', $name)->update(['location_id' => $locationId]);
        }
    }

    private function locationCode(string $name): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '-', $name));
        $code = trim($code, '-');
        return mb_substr($code !== '' ? $code : 'LOCATION', 0, 24);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
