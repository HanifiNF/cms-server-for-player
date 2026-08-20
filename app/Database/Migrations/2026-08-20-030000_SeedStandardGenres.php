<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SeedStandardGenres extends Migration
{
    private const GENRES = [
        'Action',
        'Adventure',
        'Animation',
        'Comedy',
        'Crime',
        'Documentary',
        'Drama',
        'Family',
        'Fantasy',
        'Horror',
        'Mystery',
        'Romance',
        'Sci-Fi',
        'Thriller',
        'War',
    ];

    public function up(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach (self::GENRES as $name) {
            $slug = $this->slug($name);
            if ($this->db->table('genres')->where('slug', $slug)->countAllResults() > 0) {
                continue;
            }

            $this->db->table('genres')->insert([
                'public_id' => $this->publicId($slug),
                'name' => $name,
                'slug' => $slug,
                'status' => 'active',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $publicIds = array_map(fn (string $name): string => $this->publicId($this->slug($name)), self::GENRES);
        $this->db->table('genres')->whereIn('public_id', $publicIds)->delete();
    }

    private function slug(string $value): string
    {
        return strtolower(str_replace(' ', '-', $value));
    }

    private function publicId(string $slug): string
    {
        $hex = substr(hash('sha256', 'wir-player-standard-genre:' . $slug), 0, 32);
        $hex[12] = '4';
        $hex[16] = '8';

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
