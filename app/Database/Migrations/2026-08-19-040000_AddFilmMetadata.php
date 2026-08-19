<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFilmMetadata extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('assets', [
            'synopsis'            => ['type' => 'TEXT', 'null' => true, 'after' => 'title'],
            'genre'               => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true, 'after' => 'synopsis'],
            'language'            => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'genre'],
            'subtitles'           => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true, 'after' => 'language'],
            'age_rating'          => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true, 'after' => 'subtitles'],
            'production_year'     => ['type' => 'SMALLINT', 'null' => true, 'after' => 'age_rating'],
            'release_date'        => ['type' => 'DATE', 'null' => true, 'after' => 'production_year'],
            'distributor_company' => ['type' => 'VARCHAR', 'constraint' => 180, 'null' => true, 'after' => 'release_date'],
            'poster_storage_key'  => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true, 'after' => 'distributor_company'],
            'poster_filename'     => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'poster_storage_key'],
            'poster_mime_type'    => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true, 'after' => 'poster_filename'],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('assets', [
            'synopsis', 'genre', 'language', 'subtitles', 'age_rating',
            'production_year', 'release_date', 'distributor_company',
            'poster_storage_key', 'poster_filename', 'poster_mime_type',
        ]);
    }
}
