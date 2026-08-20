<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

class CreateAssetTaxonomy extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('assets', [
            'asset_type' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'featured', 'after' => 'title'],
        ]);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'auto_increment' => true],
            'public_id' => ['type' => 'CHAR', 'constraint' => 36],
            'name' => ['type' => 'VARCHAR', 'constraint' => 80],
            'slug' => ['type' => 'VARCHAR', 'constraint' => 90],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'active'],
            'created_by' => ['type' => 'BIGINT', 'null' => true],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
            'updated_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('public_id', 'uq_genres_public_id');
        $this->forge->addUniqueKey('slug', 'uq_genres_slug');
        $this->forge->addKey(['status', 'name'], false, false, 'idx_genres_status_name');
        $this->foreignKey('created_by', 'users', 'id', 'CASCADE', 'SET NULL', 'fk_genres_creator');
        $this->forge->createTable('genres', true);

        $this->forge->addField([
            'asset_id' => ['type' => 'BIGINT'],
            'genre_id' => ['type' => 'BIGINT'],
            'created_at' => ['type' => 'TIMESTAMP', 'default' => new RawSql('CURRENT_TIMESTAMP')],
        ]);
        $this->forge->addKey(['asset_id', 'genre_id'], true);
        $this->forge->addKey('genre_id', false, false, 'idx_asset_genres_genre');
        $this->foreignKey('asset_id', 'assets', 'id', 'CASCADE', 'CASCADE', 'fk_asset_genres_asset');
        $this->foreignKey('genre_id', 'genres', 'id', 'CASCADE', 'CASCADE', 'fk_asset_genres_genre');
        $this->forge->createTable('asset_genres', true);

        foreach ($this->db->table('assets')->select('id, genre')->get()->getResultArray() as $asset) {
            $names = preg_split('/[,;|]+/', (string) ($asset['genre'] ?? '')) ?: [];
            foreach ($names as $name) {
                $name = trim($name);
                if ($name === '') continue;
                $slug = $this->slug($name);
                $genre = $this->db->table('genres')->where('slug', $slug)->get()->getRowArray();
                if ($genre === null) {
                    $this->db->table('genres')->insert([
                        'public_id' => $this->uuidV4(), 'name' => mb_substr($name, 0, 80),
                        'slug' => $slug, 'status' => 'active',
                        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $genre = $this->db->table('genres')->where('slug', $slug)->get()->getRowArray();
                }
                if ($genre !== null) $this->db->table('asset_genres')->ignore(true)->insert(['asset_id' => $asset['id'], 'genre_id' => $genre['id']]);
            }
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('asset_genres', true);
        $this->forge->dropTable('genres', true);
        $this->forge->dropColumn('assets', 'asset_type');
    }

    private function slug(string $value): string
    {
        $slug = mb_strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/u', '-', $slug);
        return trim($slug, '-') ?: 'genre-' . substr(hash('sha256', $value), 0, 12);
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private function foreignKey(string $field, string $table, string $reference, string $onUpdate, string $onDelete, string $name): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $this->forge->addForeignKey($field, $table, $reference, $onUpdate, $onDelete);
            return;
        }
        $this->forge->addForeignKey($field, $table, $reference, $onUpdate, $onDelete, $name);
    }
}
